<?php
declare(strict_types=1);

namespace Tests\Acceptance;

use Tests\Support\ApiTestCase;

/** AT-120..AT-124, BR-160..BR-163, approved interpretation 6. */
final class SecurityTest extends ApiTestCase
{
    /** @return array{0:\Tests\Support\TestClient,1:\Tests\Support\TestClient} */
    private function twoUsers(): array
    {
        $a = $this->loggedIn('a@example.nl', 'User A');
        $b = $this->loggedIn('b@example.nl', 'User B');
        $this->startProgram($a);
        $this->startProgram($b);
        return [$a, $b];
    }

    public function testAT120ReadingAnotherUsersAssignmentIs403(): void
    {
        [$a, $b] = $this->twoUsers();
        $bId = $this->aid($b, 1, 1, 1);
        $r = $a->get("/api/v1/workout-assignments/$bId");
        self::assertSame(403, $r->status);
        self::assertSame('FORBIDDEN', $r->errorCode());
        self::assertStringNotContainsString('Fast and Sweaty', (string) $r->body, 'no content of the other user leaks');
        self::assertSame(200, $b->get("/api/v1/workout-assignments/$bId")->status);
    }

    public function testAT121MutatingAnotherUsersAssignmentIsImpossible(): void
    {
        [$a, $b] = $this->twoUsers();
        $bId = $this->aid($b, 1, 1, 1);
        $before = $this->db->all('SELECT id, status, started_at, completed_at, skipped_at FROM workout_assignments ORDER BY id');
        foreach (['start', 'complete', 'skip', 'reopen'] as $action) {
            $r = $this->act($a, $bId, $action);
            self::assertSame(403, $r->status, $action);
            self::assertSame('FORBIDDEN', $r->errorCode(), $action);
        }
        self::assertSame($before, $this->db->all('SELECT id, status, started_at, completed_at, skipped_at FROM workout_assignments ORDER BY id'));
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM workout_sessions'));
        // even a started assignment of B cannot be touched
        $this->act($b, $bId, 'start');
        self::assertSame('FORBIDDEN', $this->act($a, $bId, 'complete')->errorCode());
        self::assertSame('started', $this->statusOf($b, $bId));
    }

    public function testAT122CompletingAnotherUsersSessionIs403(): void
    {
        [$a, $b] = $this->twoUsers();
        $tpl = $this->assignment($b, $this->aid($b, 1, 1, 1))['workout']['id'];
        $s = $b->post("/api/v1/workouts/$tpl/sessions")->at('data.id');
        $r = $a->post("/api/v1/workout-sessions/$s/complete");
        self::assertSame(403, $r->status);
        self::assertSame('FORBIDDEN', $r->errorCode());
        self::assertSame('started', $this->db->value('SELECT status FROM workout_sessions WHERE public_id = ?', [$s]));
    }

    public function testAT123KnowingAValidUlidGivesNoAccess(): void
    {
        [$a, $b] = $this->twoUsers();
        $ids = [$this->aid($b, 1, 1, 1), $this->aid($b, 5, 2, 6)];
        foreach ($ids as $id) {
            self::assertSame(403, $a->get("/api/v1/workout-assignments/$id")->status);
        }
        // a made-up (well-formed) id and a malformed id are plain 404s
        self::assertSame('ASSIGNMENT_NOT_FOUND', $a->get('/api/v1/workout-assignments/01ARZ3NDEKTSV4RRFFQ69G5FAV')->errorCode());
        self::assertSame('ASSIGNMENT_NOT_FOUND', $a->get('/api/v1/workout-assignments/1')->errorCode());
        self::assertSame('ASSIGNMENT_NOT_FOUND', $a->get("/api/v1/workout-assignments/1%20OR%201=1")->errorCode());
        self::assertSame('ASSIGNMENT_NOT_FOUND', $a->get('/api/v1/workout-assignments/' . urlencode("' OR '1'='1"))->errorCode());
    }

    public function testBlockEndpointsOnlyWorkOnTheCallersOwnRun(): void
    {
        $a = $this->loggedIn('a@example.nl', 'A');
        $b = $this->loggedIn('b@example.nl', 'B');
        $this->startProgram($b, 'position', 3, 1);
        $this->completeCycle($b, 1, 3);                      // B is at a block decision
        $blockId = $this->programState($b)['blocks'][0]['block']['id'];
        // A has no run at all: 404 (interpretation 6), and B's state is untouched
        self::assertSame('BLOCK_NOT_FOUND', $a->post("/api/v1/me/program/blocks/$blockId/extend")->errorCode());
        self::assertSame('BLOCK_NOT_FOUND', $a->post("/api/v1/me/program/blocks/$blockId/advance")->errorCode());
        self::assertSame('decision_required', $this->programState($b)['blocks'][0]['block']['status']);
        // A's own run is not affected by B's decision
        $this->startProgram($a);
        self::assertSame('BLOCK_NOT_READY_FOR_DECISION', $a->post("/api/v1/me/program/blocks/$blockId/advance")->errorCode());
    }

    public function testEveryUserHasAnIndependentRun(): void
    {
        [$a, $b] = $this->twoUsers();   // BR-005 is per user, both started
        $this->complete($a, $this->aid($a, 1, 1, 1));
        self::assertSame(1, $this->today($b)['assignment']['position']);
        self::assertSame(2, $this->today($a)['assignment']['position']);
        self::assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM user_programs'));
        self::assertNotSame($this->aid($a, 1, 1, 1), $this->aid($b, 1, 1, 1));
    }

    public function testAT124InternalDatabaseIdsAreNeverExposed(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $a = $this->aid($c, 1, 1, 1);
        $s = $this->act($c, $a, 'start')->at('data.session.id');
        $c->post('/api/v1/workout-sessions/' . $s . '/complete');
        $tpl = $this->assignment($c, $a)['workout']['id'];
        $e = $c->post("/api/v1/workouts/$tpl/sessions")->at('data.id');

        $responses = [
            $c->get('/api/v1/me'), $c->get('/api/v1/me/today'), $c->get('/api/v1/me/program'), $c->get('/api/v1/me/history'),
            $c->get("/api/v1/workout-assignments/$a"), $c->get("/api/v1/workouts/$tpl"),
            $this->act($c, $this->aid($c, 1, 1, 2), 'skip'), $c->post("/api/v1/workout-sessions/$e/complete"),
        ];
        $check = function (mixed $node, string $path) use (&$check): void {
            if (!is_array($node)) {
                return;
            }
            foreach ($node as $k => $v) {
                $key = (string) $k;
                if ($key === 'id' || str_ends_with($key, '_id')) {
                    if ($v !== null) {
                        self::assertIsString($v, "$path.$key must be a string id, got " . var_export($v, true));
                        self::assertMatchesRegularExpression('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $v, "$path.$key is not a public ULID");
                    }
                }
                self::assertNotContains($key, ['user_id', 'user_program_block_id', 'block_workout_id', 'workout_template_id', 'training_block_id', 'password'], "$path.$key");
                $check($v, "$path.$key");
            }
        };
        foreach ($responses as $i => $r) {
            self::assertLessThan(300, $r->status, $r->body);
            $check($r->json(), "response$i");
        }
    }

    public function testSqlInjectionAttemptsAreInert(): void
    {
        $c = $this->loggedIn();
        $r = $this->anonymous()->login("a@example.nl' OR '1'='1", "x' OR '1'='1");
        self::assertSame(401, $r->status);
        $inj = $c->get('/api/v1/me/history?page=1;DROP%20TABLE%20users');
        self::assertSame(422, $inj->status, 'non numeric page is rejected, never interpolated');
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM users'));
    }

    public function testNotesAreStoredVerbatimAndReturnedAsJsonOnly(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $evil = '<script>alert(1)</script>\' OR 1=1 --';
        $this->complete($c, $this->aid($c, 1, 1, 1), $evil);
        $r = $c->get('/api/v1/me/history');
        self::assertSame($evil, $r->at('data.0.notes'));
        self::assertStringContainsString('application/json', (string) $r->header('Content-Type'));
    }

    public function testPasswordHashIsNeverInAnyResponse(): void
    {
        $c = $this->loggedIn();
        foreach (['/api/v1/me', '/api/v1/auth/csrf'] as $path) {
            self::assertStringNotContainsString('argon2', (string) $c->get($path)->body);
        }
    }
}
