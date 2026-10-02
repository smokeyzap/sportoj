<?php
declare(strict_types=1);

namespace Tests\Acceptance;

use Tests\Support\ApiTestCase;
use Tests\Support\TestClient;

/** AT-090..AT-103, BR-110..BR-134 and interpretation 1 (repeated decisions). */
final class BlockDecisionTest extends ApiTestCase
{
    /** Run positioned on the last cycle of block 1 with that cycle completed -> block decision. */
    private function atBlock1Decision(): TestClient
    {
        $c = $this->loggedIn();
        $this->startProgram($c, 'position', 3, 1);
        $this->completeCycle($c, 1, 3);
        return $c;
    }

    private function blockId(TestClient $c, int $seq): string
    {
        return $this->programState($c)['blocks'][$seq - 1]['block']['id'];
    }

    private function extend(TestClient $c, int $seq = 1): \Tests\Support\TestResponse
    {
        return $c->post('/api/v1/me/program/blocks/' . $this->blockId($c, $seq) . '/extend');
    }

    private function advance(TestClient $c, int $seq = 1): \Tests\Support\TestResponse
    {
        return $c->post('/api/v1/me/program/blocks/' . $this->blockId($c, $seq) . '/advance');
    }

    public function testAT090LastStandardCycleProcessedGivesBlockDecision(): void
    {
        $c = $this->atBlock1Decision();
        $t = $this->today($c);
        self::assertSame('block_decision', $t['type']);
        self::assertSame('decision_required', $t['block']['status']);
        self::assertSame(1, $t['block']['sequence']);
        self::assertSame(['extend', 'advance'], $t['options']);
        self::assertSame(['completed' => 6, 'skipped' => 0, 'cycles_processed' => 3], $t['summary']);
        self::assertArrayNotHasKey('assignment', $t, 'BR-111: no workout recommendation');
        self::assertSame('decision_required', $this->programState($c)['blocks'][0]['block']['status']);
    }

    public function testAT091ExtendAddsExactlySixExtraAssignments(): void
    {
        $c = $this->atBlock1Decision();
        $r = $this->extend($c);
        self::assertSame(200, $r->status);
        self::assertSame('workout', $r->at('data.type'));

        $block = $this->programState($c)['blocks'][0];
        self::assertSame(4, $block['block']['target_cycles']);
        self::assertSame('active', $block['block']['status']);
        $extra = end($block['cycles']);
        self::assertSame(4, $extra['number']);
        self::assertTrue($extra['is_extra']);
        self::assertCount(6, $extra['assignments']);
        foreach ($extra['assignments'] as $i => $a) {
            self::assertSame($i + 1, $a['position']);
            self::assertSame('pending', $a['status']);
            self::assertTrue($a['is_extra_cycle']);
            self::assertNull($a['program_week'], 'BR-116: an extra cycle has no original week');
        }
        self::assertSame(6, (int) $this->db->value('SELECT COUNT(*) FROM workout_assignments WHERE is_extra_cycle = 1'));
        self::assertSame(90, (int) $this->db->value('SELECT COUNT(*) FROM workout_assignments'), '84 + 6');
    }

    public function testAT092AfterExtendTheFirstPositionOfTheExtraCycleIsRecommended(): void
    {
        $c = $this->atBlock1Decision();
        $r = $this->extend($c);
        self::assertSame([4, 1, true], [$r->at('data.assignment.cycle'), $r->at('data.assignment.position'), $r->at('data.assignment.is_extra_cycle')]);
        // BR-144: totals grow from 18/18 to 24, 18 processed
        self::assertSame(['block_number' => 1, 'block_count' => 5, 'cycle' => 4, 'target_cycles' => 4, 'processed' => 18, 'total' => 24, 'completed' => 6, 'skipped' => 0, 'pending' => 6], $r->at('data.progress'));
    }

    public function testAT093RepeatedExtendDoesNotAddASecondCycle(): void
    {
        $c = $this->atBlock1Decision();
        self::assertSame(200, $this->extend($c)->status);
        $again = $this->extend($c);
        self::assertSame(409, $again->status);
        self::assertSame('BLOCK_NOT_READY_FOR_DECISION', $again->errorCode());
        self::assertSame(4, $this->programState($c)['blocks'][0]['block']['target_cycles']);
        self::assertSame(6, (int) $this->db->value('SELECT COUNT(*) FROM workout_assignments WHERE is_extra_cycle = 1'));
    }

    public function testAT093ConcurrentExtendCreatesOnlyOneExtraCycle(): void
    {
        $c = $this->atBlock1Decision();
        $blockId = $this->blockId($c, 1);
        $results = $this->parallel(5, function () use ($c, $blockId): int {
            $client = new TestClient($this->makeApp(), false);
            $client->cookies = $c->cookies;
            $client->csrf = $c->csrf;
            return $client->post("/api/v1/me/program/blocks/$blockId/extend")->status;
        });
        sort($results);
        self::assertSame([200, 409, 409, 409, 409], $results);
        self::assertSame(6, (int) $this->db->value('SELECT COUNT(*) FROM workout_assignments WHERE is_extra_cycle = 1'));
        self::assertSame(4, (int) $this->db->value('SELECT target_cycles FROM user_program_blocks WHERE status = "active"'));
    }

    public function testAT094ExtraCycleCompletedGivesDecisionAgainAndCanBeExtendedAgain(): void
    {
        $c = $this->atBlock1Decision();
        $this->extend($c);
        $this->completeCycle($c, 1, 4);
        self::assertSame('block_decision', $this->today($c)['type']);
        self::assertSame(['completed' => 12, 'skipped' => 0, 'cycles_processed' => 4], $this->today($c)['summary']);

        $r = $this->extend($c);
        self::assertSame(200, $r->status);
        self::assertSame(5, $this->programState($c)['blocks'][0]['block']['target_cycles']);
        self::assertSame(12, (int) $this->db->value('SELECT COUNT(*) FROM workout_assignments WHERE is_extra_cycle = 1'));
        self::assertSame(5, $r->at('data.assignment.cycle'));
        $this->completeCycle($c, 1, 5);
        self::assertSame('block_decision', $this->today($c)['type'], 'no functional maximum (BR-117)');
    }

    public function testAT095AdvanceActivatesTheNextBlock(): void
    {
        $c = $this->atBlock1Decision();
        $r = $this->advance($c);
        self::assertSame(200, $r->status);
        self::assertSame('workout', $r->at('data.type'));
        self::assertSame([2, 1, 1], [$r->at('data.assignment.block.sequence'), $r->at('data.assignment.cycle'), $r->at('data.assignment.position')]);
        $statuses = array_map(static fn ($b) => $b['block']['status'], $this->programState($c)['blocks']);
        self::assertSame(['completed', 'active', 'not_started', 'not_started', 'not_started'], $statuses);
        self::assertNotNull($this->db->value("SELECT completed_at FROM user_program_blocks WHERE status = 'completed'"));
        self::assertNotNull($this->db->value("SELECT started_at FROM user_program_blocks WHERE status = 'active'"));
    }

    public function testAT096EarlyDecisionsAre409(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        foreach ([$this->advance($c), $this->extend($c)] as $r) {
            self::assertSame(409, $r->status);
            self::assertSame('BLOCK_NOT_READY_FOR_DECISION', $r->errorCode());
        }
        // a block that is merely not_started is not ready either
        self::assertSame('BLOCK_NOT_READY_FOR_DECISION', $this->advance($c, 2)->errorCode());
        self::assertSame('BLOCK_NOT_READY_FOR_DECISION', $this->extend($c, 3)->errorCode());
        self::assertSame('active', $this->programState($c)['blocks'][0]['block']['status']);
        self::assertSame(84, (int) $this->db->value('SELECT COUNT(*) FROM workout_assignments'));
    }

    public function testInterpretation1RepeatedAdvanceIs409(): void
    {
        $c = $this->atBlock1Decision();
        $this->advance($c);
        $again = $this->advance($c);
        self::assertSame(409, $again->status);
        self::assertSame('BLOCK_NOT_READY_FOR_DECISION', $again->errorCode());
        self::assertSame('active', $this->programState($c)['blocks'][1]['block']['status']);
    }

    public function testAT097ContinuationIsResetOnAdvance(): void
    {
        $c = $this->atBlock1Decision();
        // BR-123 is exercised for real by planting stale state: the block boundary must wipe it.
        $this->db->exec("UPDATE user_programs SET continuation_mode = 'last_workout_sequence', continuation_anchor_assignment_public_id = ?, continuation_source_assignment_public_id = ?",
            [$this->aid($c, 1, 3, 6), $this->aid($c, 1, 3, 5)]);
        $this->advance($c);
        $row = $this->rowOf('SELECT continuation_mode, continuation_decision_required, continuation_anchor_assignment_public_id, continuation_source_assignment_public_id FROM user_programs');
        self::assertSame(['program_sequence', 0, null, null], array_values($row));
        self::assertSame('program_sequence', $this->programState($c)['continuation_mode']);
    }

    public function testNaturalFlowLeavesNoContinuationStateAtTheBoundary(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c, 'position', 3, 1);
        $this->complete($c, $this->aid($c, 1, 3, 1));
        $this->complete($c, $this->aid($c, 1, 3, 4));
        $c->post('/api/v1/me/program/continuation', ['mode' => 'last_workout_sequence']);
        $this->complete($c, $this->aid($c, 1, 3, 5));
        $this->complete($c, $this->aid($c, 1, 3, 6));
        $this->complete($c, $this->aid($c, 1, 3, 2));
        $this->complete($c, $this->aid($c, 1, 3, 3));
        self::assertSame('block_decision', $this->today($c)['type']);
        $row = $this->rowOf('SELECT continuation_mode, continuation_decision_required, continuation_anchor_assignment_public_id, continuation_source_assignment_public_id FROM user_programs');
        self::assertSame(['program_sequence', 0, null, null], array_values($row));
    }

    public function testUnknownBlockIs404(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        foreach (['extend', 'advance'] as $action) {
            foreach (['01ARZ3NDEKTSV4RRFFQ69G5FAV', 'not-an-id', '7'] as $id) {
                $r = $c->post("/api/v1/me/program/blocks/$id/$action");
                self::assertSame(404, $r->status, "$action $id");
                self::assertSame('BLOCK_NOT_FOUND', $r->errorCode());
            }
        }
    }

    public function testBlockDecisionWithoutAnyProgramIs404(): void
    {
        $c = $this->loggedIn();
        self::assertSame('BLOCK_NOT_FOUND', $c->post('/api/v1/me/program/blocks/0J6147BJFBEFFJK2CH4114053K/extend')->errorCode());
    }

    // ---- last block and program end ----

    private function atLastBlockDecision(): TestClient
    {
        $c = $this->loggedIn();
        $this->startProgram($c, 'position', 14, 1);   // block 5, cycle 2 (the last standard cycle)
        $this->completeCycle($c, 5, 2);
        return $c;
    }

    public function testAT100LastBlockOffersExtendAndCompleteProgramButNotAdvance(): void
    {
        $c = $this->atLastBlockDecision();
        $t = $this->today($c);
        self::assertSame('block_decision', $t['type']);
        self::assertSame(5, $t['block']['sequence']);
        self::assertSame(['extend', 'complete_program'], $t['options']);
        $r = $this->advance($c, 5);
        self::assertSame(409, $r->status);
        self::assertSame('BLOCK_NOT_READY_FOR_DECISION', $r->errorCode());
        self::assertSame('decision_required', $this->programState($c)['blocks'][4]['block']['status']);
    }

    public function testLastBlockCanBeExtended(): void
    {
        $c = $this->atLastBlockDecision();
        $r = $this->extend($c, 5);
        self::assertSame(200, $r->status);
        self::assertSame(3, $r->at('data.assignment.cycle'));
        $this->completeCycle($c, 5, 3);
        self::assertSame(['extend', 'complete_program'], $this->today($c)['options']);
    }

    public function testAT101CompleteProgram(): void
    {
        $c = $this->atLastBlockDecision();
        $r = $c->post('/api/v1/me/program/complete');
        self::assertSame(200, $r->status);
        self::assertSame('program_completed', $r->at('data.type'));
        self::assertNotNull($r->at('data.completed_at'));

        $state = $this->programState($c);
        self::assertSame('completed', $state['status']);
        self::assertSame('completed', $state['blocks'][4]['block']['status']);
        self::assertSame($r->at('data.user_program_id'), $state['id']);
        self::assertSame('completed', $this->db->value('SELECT status FROM user_programs'));
        self::assertNotNull($this->db->value('SELECT completed_at FROM user_programs'));
        self::assertSame('program_completed', $this->today($c)['type']);
        self::assertSame('program_completed', $this->today($c)['type'], 'stays until a new run is started (BR-132)');
    }

    public function testCompleteProgramTooEarlyAndRepeated(): void
    {
        $c = $this->loggedIn();
        self::assertSame('PROGRAM_NOT_READY_TO_COMPLETE', $c->post('/api/v1/me/program/complete')->errorCode(), 'no program at all');
        $this->startProgram($c);
        $r = $c->post('/api/v1/me/program/complete');
        self::assertSame(409, $r->status);
        self::assertSame('PROGRAM_NOT_READY_TO_COMPLETE', $r->errorCode());

        $c2 = $this->loggedIn('b@example.nl', 'User B');
        $this->startProgram($c2, 'position', 14, 1);
        $this->completeCycle($c2, 5, 2);
        self::assertSame(200, $c2->post('/api/v1/me/program/complete')->status);
        $again = $c2->post('/api/v1/me/program/complete');
        self::assertSame(409, $again->status, 'interpretation 1');
        self::assertSame('BLOCK_NOT_READY_FOR_DECISION', $again->errorCode());
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM user_programs WHERE status = 'completed'"));
    }

    public function testDecisionsAfterCompletionAre409(): void
    {
        $c = $this->atLastBlockDecision();
        $block = $this->blockId($c, 5);
        $c->post('/api/v1/me/program/complete');
        self::assertSame('BLOCK_NOT_READY_FOR_DECISION', $c->post("/api/v1/me/program/blocks/$block/extend")->errorCode());
        self::assertSame('BLOCK_NOT_READY_FOR_DECISION', $c->post("/api/v1/me/program/blocks/$block/advance")->errorCode());
    }

    public function testAT102RestartCreatesANewRunAndKeepsTheOldOne(): void
    {
        $c = $this->atLastBlockDecision();
        $oldRun = $this->programState($c)['id'];
        $c->post('/api/v1/me/program/complete');
        $sessionsBefore = $this->db->all('SELECT public_id, status, completed_at FROM workout_sessions ORDER BY id');
        $oldAssignments = $this->db->all('SELECT public_id, status FROM workout_assignments ORDER BY id');

        $r = $c->post('/api/v1/me/program/restart', ['start_mode' => 'beginning']);
        self::assertSame(201, $r->status);
        self::assertSame('workout', $r->at('data.type'));
        self::assertSame([1, 1, 1], [$r->at('data.assignment.block.sequence'), $r->at('data.assignment.cycle'), $r->at('data.assignment.position')]);
        $this->forgetIds();

        self::assertNotSame($oldRun, $this->programState($c)['id']);
        self::assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM user_programs'));
        self::assertSame(count($oldAssignments) + 84, (int) $this->db->value('SELECT COUNT(*) FROM workout_assignments'));
        self::assertSame($oldAssignments, array_slice($this->db->all('SELECT public_id, status FROM workout_assignments ORDER BY id'), 0, count($oldAssignments)), 'BR-134');
        self::assertSame($sessionsBefore, $this->db->all('SELECT public_id, status, completed_at FROM workout_sessions ORDER BY id'));
        self::assertSame('completed', $this->db->value('SELECT status FROM user_programs WHERE public_id = ?', [$oldRun]));
        // a second restart while the new run is active is refused
        self::assertSame('ACTIVE_PROGRAM_EXISTS', $c->post('/api/v1/me/program/restart', ['start_mode' => 'beginning'])->errorCode());
    }

    public function testRestartAcceptsAStartPositionToo(): void
    {
        $c = $this->atLastBlockDecision();
        $c->post('/api/v1/me/program/complete');
        $r = $c->post('/api/v1/me/program/restart', ['start_mode' => 'position', 'original_week' => 4, 'day_sequence' => 2]);
        self::assertSame(201, $r->status);
        self::assertSame([2, 1, 2], [$r->at('data.assignment.block.sequence'), $r->at('data.assignment.cycle'), $r->at('data.assignment.position')]);
    }

    public function testStartAfterCompletionAlsoWorksAsRestart(): void
    {
        $c = $this->atLastBlockDecision();
        $c->post('/api/v1/me/program/complete');
        self::assertSame(201, $c->post('/api/v1/me/program/start', ['start_mode' => 'beginning'])->status);
    }

    public function testAT103HistoryAcrossRunsKeepsIdsApart(): void
    {
        $c = $this->atLastBlockDecision();
        $c->post('/api/v1/me/program/complete');
        $c->post('/api/v1/me/program/restart', ['start_mode' => 'beginning']);
        $this->forgetIds();
        $this->complete($c, $this->aid($c, 1, 1, 1), 'tweede run');

        $h = $c->get('/api/v1/me/history')->at('data');
        self::assertCount(7, $h);
        self::assertSame('tweede run', $h[0]['notes'], 'newest first');
        self::assertCount(7, array_unique(array_column($h, 'session_id')));
        self::assertCount(7, array_unique(array_column($h, 'assignment_id')));
        self::assertSame([1, 1], [$h[0]['block']['sequence'], $h[0]['cycle']]);
        self::assertSame(5, $h[1]['block']['sequence']);
        // sessions of both runs reference their own run
        self::assertSame(2, (int) $this->db->value('SELECT COUNT(DISTINCT user_program_id) FROM workout_sessions'));
    }

    public function testBlocksFromAnOldRunAreNotAddressableAfterRestart(): void
    {
        $c = $this->atLastBlockDecision();
        $oldAssignment = $this->aid($c, 5, 2, 1);
        $c->post('/api/v1/me/program/complete');
        $c->post('/api/v1/me/program/restart', ['start_mode' => 'position', 'original_week' => 14, 'day_sequence' => 1]);
        // old assignment lives in a completed block: it cannot be reopened
        self::assertSame('INVALID_ASSIGNMENT_STATE', $this->act($c, $oldAssignment, 'reopen')->errorCode());
        self::assertSame('completed', $this->statusOf($c, $oldAssignment));
    }
}
