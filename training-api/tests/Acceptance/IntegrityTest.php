<?php
declare(strict_types=1);

namespace Tests\Acceptance;

use Tests\Support\ApiTestCase;
use Tests\Support\TestClient;

/** AT-130..AT-133 plus concurrency guarantees (BR-033, BR-074, BR-118, BR-184). */
final class IntegrityTest extends ApiTestCase
{
    public function testAT130DuplicateAssignmentCombinationIsRejectedByTheDatabase(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $row = $this->rowOf('SELECT user_program_block_id, block_workout_id, cycle_number, sequence FROM workout_assignments LIMIT 1');
        try {
            $this->db->exec(
                "INSERT INTO workout_assignments (public_id, user_program_block_id, block_workout_id, cycle_number, sequence, status) VALUES (?, ?, ?, ?, ?, 'pending')",
                ['01ARZ3NDEKTSV4RRFFQ69G5FAV', $row['user_program_block_id'], $row['block_workout_id'], $row['cycle_number'], $row['sequence']]
            );
            self::fail('duplicate insert must fail');
        } catch (\PDOException $e) {
            self::assertSame(1062, $e->errorInfo[1]);
            self::assertStringContainsString('uq_workout_assignments_cycle_workout', $e->getMessage());
        }
    }

    public function testDatabaseConstraintsBackTheBusinessRules(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $bad = [
            "UPDATE workout_assignments SET status = 'bogus' LIMIT 1",
            "UPDATE user_program_blocks SET status = 'bogus' LIMIT 1",
            "UPDATE workout_sessions SET session_type = 'extra'",   // program session with an assignment
        ];
        $this->act($c, $this->aid($c, 1, 1, 1), 'start');
        foreach ($bad as $sql) {
            try {
                $this->db->exec($sql);
                self::fail("must violate a constraint: $sql");
            } catch (\PDOException $e) {
                self::assertContains((int) $e->errorInfo[1], [4025, 3819], $sql);   // CHECK constraint failed (MariaDB / MySQL)
            }
        }
    }

    public function testAT131FailureInTheMiddleOfCompleteRollsEverythingBack(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $id = $this->aid($c, 1, 1, 1);
        $this->db->pdo()->exec("CREATE TRIGGER tt_fail_session_complete BEFORE UPDATE ON workout_sessions FOR EACH ROW
            BEGIN IF NEW.status = 'completed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced failure'; END IF; END");

        // from pending: implicit start + completion in one transaction
        $r = $this->act($c, $id, 'complete');
        self::assertSame(500, $r->status);
        self::assertSame('INTERNAL_ERROR', $r->errorCode());
        self::assertStringNotContainsString('forced failure', (string) $r->body);
        $row = $this->rowOf('SELECT status, started_at, completed_at FROM workout_assignments WHERE public_id = ?', [$id]);
        self::assertSame(['pending', null, null], array_values($row), 'assignment must not be half changed');
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM workout_sessions'), 'no orphan session');

        // from started: it stays cleanly started
        $this->db->pdo()->exec('DROP TRIGGER tt_fail_session_complete');
        $this->act($c, $id, 'start');
        $this->db->pdo()->exec("CREATE TRIGGER tt_fail_session_complete BEFORE UPDATE ON workout_sessions FOR EACH ROW
            BEGIN IF NEW.status = 'completed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced failure'; END IF; END");
        self::assertSame(500, $this->act($c, $id, 'complete')->status);
        self::assertSame('started', $this->statusOf($c, $id));
        self::assertSame(['started'], array_column($this->db->all('SELECT status FROM workout_sessions'), 'status'));
        self::assertSame(0, (int) $this->db->value('SELECT continuation_decision_required FROM user_programs'));

        $this->db->pdo()->exec('DROP TRIGGER tt_fail_session_complete');
        self::assertSame(200, $this->act($c, $id, 'complete')->status, 'and it works once the fault is gone');
    }

    public function testAT132FailureInTheMiddleOfExtendLeavesNoPartialCycle(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c, 'position', 3, 1);
        $this->completeCycle($c, 1, 3);
        $blockId = $this->programState($c)['blocks'][0]['block']['id'];
        $this->db->pdo()->exec("CREATE TRIGGER tt_fail_extend BEFORE INSERT ON workout_assignments FOR EACH ROW
            BEGIN IF NEW.is_extra_cycle = 1 AND NEW.sequence = 4 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced failure'; END IF; END");

        $r = $c->post("/api/v1/me/program/blocks/$blockId/extend");
        self::assertSame(500, $r->status);
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM workout_assignments WHERE is_extra_cycle = 1'), 'not 1-5 of 6');
        self::assertSame(3, (int) $this->db->value('SELECT target_cycles FROM user_program_blocks WHERE status = "decision_required"'));
        self::assertSame('block_decision', $this->today($c)['type']);

        $this->db->pdo()->exec('DROP TRIGGER tt_fail_extend');
        self::assertSame(200, $c->post("/api/v1/me/program/blocks/$blockId/extend")->status);
        self::assertSame(6, (int) $this->db->value('SELECT COUNT(*) FROM workout_assignments WHERE is_extra_cycle = 1'));
    }

    public function testFailureWhileStartingAProgramLeavesNothingBehind(): void
    {
        $c = $this->loggedIn();
        $this->db->pdo()->exec("CREATE TRIGGER tt_fail_extend BEFORE INSERT ON workout_assignments FOR EACH ROW
            BEGIN IF NEW.cycle_number = 2 AND NEW.sequence = 3 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced failure'; END IF; END");
        self::assertSame(500, $c->post('/api/v1/me/program/start', ['start_mode' => 'beginning'])->status);
        foreach (['user_programs', 'user_program_blocks', 'workout_assignments'] as $t) {
            self::assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM $t"), $t);
        }
        $this->db->pdo()->exec('DROP TRIGGER tt_fail_extend');
        self::assertSame(201, $c->post('/api/v1/me/program/start', ['start_mode' => 'beginning'])->status);
    }

    public function testAT133TimestampsAreStoredInUtc(): void
    {
        date_default_timezone_set('Pacific/Auckland');
        try {
            $c = $this->loggedIn();
            $c->patch('/api/v1/me', ['timezone' => 'Asia/Tokyo']);
            $this->startProgram($c);
            $r = $this->complete($c, $this->aid($c, 1, 1, 1));
        } finally {
            date_default_timezone_set('UTC');
        }
        $stored = (string) $this->db->value('SELECT completed_at FROM workout_sessions');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $diff = abs($now->getTimestamp() - (new \DateTimeImmutable($stored, new \DateTimeZone('UTC')))->getTimestamp());
        self::assertLessThan(30, $diff, "stored $stored vs UTC now " . $now->format('c'));
        self::assertStringEndsWith('Z', $r->at('data.session.completed_at'));
        self::assertSame(substr($stored, 0, 10), substr(str_replace('T', ' ', $r->at('data.session.completed_at')), 0, 10));
        self::assertSame('+00:00', (string) $this->db->value('SELECT @@session.time_zone'));
    }

    public function testConcurrentCompleteOfTheSameAssignmentCreatesOneCompletion(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $id = $this->aid($c, 1, 1, 1);
        $results = $this->parallel(5, function () use ($c, $id): array {
            $client = new TestClient($this->makeApp(), false);
            $client->cookies = $c->cookies;
            $client->csrf = $c->csrf;
            $r = $client->post("/api/v1/workout-assignments/$id/complete");
            return [$r->status, $r->at('data.session.id')];
        });
        self::assertSame([200], array_values(array_unique(array_column($results, 0))));
        self::assertCount(1, array_unique(array_column($results, 1)), 'all callers see the same session');
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM workout_sessions'));
        self::assertSame(1, $c->get('/api/v1/me/history')->at('meta.total'));
    }

    public function testConcurrentStartsOfDifferentAssignmentsAllowOnlyOneStartedWorkout(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $ids = [$this->aid($c, 1, 1, 1), $this->aid($c, 1, 1, 2), $this->aid($c, 1, 1, 3), $this->aid($c, 1, 1, 4)];
        $results = $this->parallel(4, function (int $i) use ($c, $ids): int {
            $client = new TestClient($this->makeApp(), false);
            $client->cookies = $c->cookies;
            $client->csrf = $c->csrf;
            return $client->post("/api/v1/workout-assignments/{$ids[$i]}/start")->status;
        });
        sort($results);
        self::assertSame([200, 409, 409, 409], $results, 'BR-033');
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM workout_assignments WHERE status = 'started'"));
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM workout_sessions WHERE status = 'started'"));
    }

    public function testConcurrentSkipAndCompleteLeaveAConsistentState(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $id = $this->aid($c, 1, 1, 1);
        $results = $this->parallel(2, function (int $i) use ($c, $id): int {
            $client = new TestClient($this->makeApp(), false);
            $client->cookies = $c->cookies;
            $client->csrf = $c->csrf;
            return $client->post("/api/v1/workout-assignments/$id/" . ($i === 0 ? 'skip' : 'complete'))->status;
        });
        sort($results);
        self::assertSame([200, 409], $results, 'the loser sees the winner\'s final state and is refused');
        $status = $this->db->value('SELECT status FROM workout_assignments WHERE public_id = ?', [$id]);
        $sessions = (int) $this->db->value('SELECT COUNT(*) FROM workout_sessions WHERE status = "completed"');
        self::assertSame($status === 'completed' ? 1 : 0, $sessions);
    }

    public function testDeletingProgressIsNotPossibleThroughRestrictingForeignKeys(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $this->complete($c, $this->aid($c, 1, 1, 1));
        foreach (['DELETE FROM users', 'DELETE FROM user_programs', 'DELETE FROM workout_templates', 'DELETE FROM workout_assignments WHERE status = "completed"'] as $sql) {
            try {
                $this->db->exec($sql);
                self::fail("BR-185: $sql must be refused");
            } catch (\PDOException $e) {
                self::assertSame(1451, $e->errorInfo[1], $sql);
            }
        }
    }
}
