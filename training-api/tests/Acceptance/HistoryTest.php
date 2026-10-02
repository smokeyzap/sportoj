<?php
declare(strict_types=1);

namespace Tests\Acceptance;

use App\Support\Clock;
use Tests\Support\ApiTestCase;

/** AT-110..AT-113, BR-140..BR-145. */
final class HistoryTest extends ApiTestCase
{
    private function at(string $when): void
    {
        Clock::freeze(new \DateTimeImmutable($when, new \DateTimeZone('UTC')));
    }

    public function testAT110NewestEventFirst(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $this->at('2026-08-17 19:30:00');
        $this->complete($c, $this->aid($c, 1, 1, 1));
        $this->at('2026-08-18 19:30:00');
        $this->skipA($c, $this->aid($c, 1, 1, 2));
        $this->at('2026-08-19 07:00:00');
        $this->complete($c, $this->aid($c, 1, 1, 3));

        $h = $c->get('/api/v1/me/history')->at('data');
        self::assertSame(['completed', 'skipped', 'completed'], array_column($h, 'event_type'));
        self::assertSame(['2026-08-19T07:00:00Z', '2026-08-18T19:30:00Z', '2026-08-17T19:30:00Z'], array_column($h, 'occurred_at'));
        self::assertSame(['Core Crusher', 'Leg Day Love', 'Fast and Sweaty'], array_map(static fn ($i) => $i['workout']['name'], $h));
    }

    public function testEventsWithTheSameTimestampKeepAStableOrder(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $this->at('2026-08-17 19:30:00');
        $this->complete($c, $this->aid($c, 1, 1, 1));
        $this->complete($c, $this->aid($c, 1, 1, 2));
        $a = $c->get('/api/v1/me/history')->at('data');
        $b = $c->get('/api/v1/me/history')->at('data');
        self::assertSame($a, $b);
        self::assertSame('Leg Day Love', $a[0]['workout']['name'], 'later id first on a tie');
    }

    public function testAT111PriorToStartIsNeverShown(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c, 'position', 6, 4);
        self::assertSame(0, $c->get('/api/v1/me/history')->at('meta.total'));
        self::assertSame([], $c->get('/api/v1/me/history')->at('data'));
        $this->complete($c, $this->aid($c, 2, 3, 4));
        self::assertSame(1, $c->get('/api/v1/me/history')->at('meta.total'));
    }

    public function testAT112SkippedAppearsAsItsOwnItem(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $id = $this->aid($c, 1, 1, 1);
        $this->skipA($c, $id);
        $item = $c->get('/api/v1/me/history')->at('data.0');
        self::assertSame('skipped', $item['event_type']);
        self::assertSame($id, $item['assignment_id']);
        self::assertNull($item['session_id']);
        self::assertNull($item['notes']);
        self::assertSame(1, $item['block']['sequence']);
        self::assertSame(1, $item['cycle']);
        self::assertSame('Fast and Sweaty', $item['workout']['name']);

        $this->reopenA($c, $id);
        self::assertSame(0, $c->get('/api/v1/me/history')->at('meta.total'), 'reopened skip leaves the history');
    }

    public function testAT113Pagination(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $this->completeCycle($c, 1, 1);
        $this->completeCycle($c, 1, 2);
        $this->completeCycle($c, 1, 3);
        $this->completeCycle($c, 1, 3, []);   // (no-op, keeps the helper symmetrical)
        // 18 completions so far; add extras to pass 30
        $tpl = $this->assignment($c, $this->aid($c, 2, 1, 1))['workout']['id'];
        for ($i = 0; $i < 12; $i++) {
            $s = $c->post("/api/v1/workouts/$tpl/sessions")->at('data.id');
            $c->post("/api/v1/workout-sessions/$s/complete");
        }
        $p1 = $c->get('/api/v1/me/history?page=1&per_page=25');
        self::assertCount(25, $p1->at('data'));
        self::assertSame(['page' => 1, 'per_page' => 25, 'total' => 30, 'last_page' => 2], $p1->at('meta'));
        $p2 = $c->get('/api/v1/me/history?page=2&per_page=25');
        self::assertCount(5, $p2->at('data'));
        self::assertSame(['page' => 2, 'per_page' => 25, 'total' => 30, 'last_page' => 2], $p2->at('meta'));
        self::assertSame([], array_intersect(array_column($p1->at('data'), 'session_id'), array_column($p2->at('data'), 'session_id')));
        $p3 = $c->get('/api/v1/me/history?page=3&per_page=25');
        self::assertSame([], $p3->at('data'));
        self::assertSame(30, $p3->at('meta.total'));

        $default = $c->get('/api/v1/me/history');
        self::assertSame(25, $default->at('meta.per_page'));
        self::assertCount(25, $default->at('data'));
        self::assertCount(100 > 30 ? 30 : 100, $c->get('/api/v1/me/history?per_page=100')->at('data'));
    }

    public function testEmptyHistoryHasLastPageOne(): void
    {
        $c = $this->loggedIn();
        self::assertSame(['page' => 1, 'per_page' => 25, 'total' => 0, 'last_page' => 1], $c->get('/api/v1/me/history')->at('meta'));
    }

    public function testInvalidPaginationParameters(): void
    {
        $c = $this->loggedIn();
        foreach (['page=0', 'page=-1', 'page=abc', 'per_page=0', 'per_page=101', 'per_page=x', 'page=1.5'] as $qs) {
            $r = $c->get("/api/v1/me/history?$qs");
            self::assertSame(422, $r->status, $qs);
            self::assertSame('VALIDATION_ERROR', $r->errorCode(), $qs);
        }
        self::assertSame(200, $c->get('/api/v1/me/history?per_page=100')->status);
    }

    public function testCancelledSessionsAreHidden(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $this->complete($c, $this->aid($c, 1, 1, 1));
        $this->act($c, $this->aid($c, 1, 1, 2), 'start');
        $this->skipA($c, $this->aid($c, 1, 1, 2));   // started then skipped -> cancelled session
        $this->reopenA($c, $this->aid($c, 1, 1, 1));
        $items = $c->get('/api/v1/me/history')->at('data');
        self::assertSame(['skipped'], array_column($items, 'event_type'), 'only the explicit skip remains; cancelled sessions never show as workouts (BR-145)');
        self::assertGreaterThanOrEqual(2, (int) $this->db->value("SELECT COUNT(*) FROM workout_sessions WHERE status = 'cancelled'"));
    }

    public function testHistoryItemsCarryBlockAndCycleForProgramWorkouts(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c, 'position', 6, 4);
        $this->complete($c, $this->aid($c, 2, 3, 4), 'n');
        $i = $c->get('/api/v1/me/history')->at('data.0');
        self::assertSame('completed', $i['event_type']);
        self::assertSame(2, $i['block']['sequence']);
        self::assertSame(3, $i['cycle']);
        self::assertSame($this->aid($c, 2, 3, 4), $i['assignment_id']);
    }

    public function testHistoryIsPerUser(): void
    {
        $a = $this->loggedIn('a@example.nl', 'A');
        $b = $this->loggedIn('b@example.nl', 'B');
        $this->startProgram($a);
        $this->startProgram($b);
        $this->complete($a, $this->aid($a, 1, 1, 1));
        self::assertSame(1, $a->get('/api/v1/me/history')->at('meta.total'));
        self::assertSame(0, $b->get('/api/v1/me/history')->at('meta.total'));
    }
}
