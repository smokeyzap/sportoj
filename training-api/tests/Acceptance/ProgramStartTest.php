<?php
declare(strict_types=1);

namespace Tests\Acceptance;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ApiTestCase;

/** AT-020..AT-023 and BR-005..BR-016. */
final class ProgramStartTest extends ApiTestCase
{
    public function testAT020StartFromBeginning(): void
    {
        $c = $this->loggedIn();
        $r = $this->startProgram($c);
        $d = $r->data();

        self::assertSame('workout', $d['type']);
        self::assertSame([1, 1, 1], [$d['assignment']['block']['sequence'], $d['assignment']['cycle'], $d['assignment']['position']]);
        self::assertSame(84, (int) $this->db->value('SELECT COUNT(*) FROM workout_assignments'));

        $statuses = array_map(static fn ($b) => $b['block']['status'], $this->programState($c)['blocks']);
        self::assertSame(['active', 'not_started', 'not_started', 'not_started', 'not_started'], $statuses);
        self::assertSame(84, (int) $this->db->value("SELECT COUNT(*) FROM workout_assignments WHERE status = 'pending'"));
        self::assertSame(['block_number' => 1, 'block_count' => 5, 'cycle' => 1, 'target_cycles' => 3, 'processed' => 0, 'total' => 18, 'completed' => 0, 'skipped' => 0, 'pending' => 18], $d['progress']);
    }

    public function testAT021StartWeek6Thursday(): void
    {
        $c = $this->loggedIn();
        $d = $this->startProgram($c, 'position', 6, 4)->data();

        self::assertSame('workout', $d['type']);
        self::assertSame([2, 3, 4], [$d['assignment']['block']['sequence'], $d['assignment']['cycle'], $d['assignment']['position']]);
        self::assertSame('pending', $d['assignment']['status']);

        $state = $this->programState($c);
        self::assertSame(['prior_to_start', 'active', 'not_started', 'not_started', 'not_started'], array_map(static fn ($b) => $b['block']['status'], $state['blocks']));

        // block 1: everything prior_to_start; block 2 cycle 1+2 prior, cycle 3 positions 1-3 prior, 4-6 pending
        foreach ($state['blocks'][0]['cycles'] as $cy) {
            foreach ($cy['assignments'] as $a) {
                self::assertSame('prior_to_start', $a['status']);
            }
        }
        $b2 = $state['blocks'][1]['cycles'];
        foreach ([0, 1] as $i) {
            foreach ($b2[$i]['assignments'] as $a) {
                self::assertSame('prior_to_start', $a['status']);
            }
        }
        self::assertSame(['prior_to_start', 'prior_to_start', 'prior_to_start', 'pending', 'pending', 'pending'], array_column($b2[2]['assignments'], 'status'));
        foreach ($state['blocks'][2]['cycles'] as $cy) {
            foreach ($cy['assignments'] as $a) {
                self::assertSame('pending', $a['status']);
            }
        }
        self::assertSame(15, $d['progress']['processed']);

        // BR-015 / AT-111: prior_to_start never shows up as history
        self::assertSame(0, $c->get('/api/v1/me/history')->at('meta.total'));
        // programme week of the first recommended workout (block 2 starts at original week 4, cycle 3 -> week 6)
        self::assertSame(6, $d['assignment']['program_week']);
    }

    #[DataProvider('invalidPositions')]
    public function testAT022InvalidStartPosition(int $week, int $day): void
    {
        $c = $this->loggedIn();
        $r = $c->post('/api/v1/me/program/start', ['start_mode' => 'position', 'original_week' => $week, 'day_sequence' => $day]);
        self::assertSame(422, $r->status);
        self::assertSame('INVALID_START_POSITION', $r->errorCode());
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM user_programs'));
    }

    /** @return array<string,array{int,int}> */
    public static function invalidPositions(): array
    {
        return ['week 0' => [0, 1], 'week 15' => [15, 1], 'day 7' => [3, 7], 'day 0' => [3, 0]];
    }

    public function testStartValidationErrors(): void
    {
        $c = $this->loggedIn();
        self::assertSame('VALIDATION_ERROR', $c->post('/api/v1/me/program/start', ['start_mode' => 'nonsense'])->errorCode());
        self::assertSame('VALIDATION_ERROR', $c->post('/api/v1/me/program/start', [])->errorCode());
        self::assertSame('VALIDATION_ERROR', $c->post('/api/v1/me/program/start', ['start_mode' => 'position'])->errorCode());
        self::assertSame('VALIDATION_ERROR', $c->post('/api/v1/me/program/start', ['start_mode' => 'position', 'original_week' => '6', 'day_sequence' => 1])->errorCode());
        self::assertSame('VALIDATION_ERROR', $c->post('/api/v1/me/program/start', '{not json')->errorCode());
    }

    public function testAT023NoSecondActiveRun(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $r = $c->post('/api/v1/me/program/start', ['start_mode' => 'beginning']);
        self::assertSame(409, $r->status);
        self::assertSame('ACTIVE_PROGRAM_EXISTS', $r->errorCode());
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM user_programs'));
        self::assertSame(84, (int) $this->db->value('SELECT COUNT(*) FROM workout_assignments'));
    }

    public function testRestartWhileActiveIsRejected(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $r = $c->post('/api/v1/me/program/restart', ['start_mode' => 'beginning']);
        self::assertSame(409, $r->status);
        self::assertSame('ACTIVE_PROGRAM_EXISTS', $r->errorCode());
    }

    public function testOnboardingBeforeStart(): void
    {
        $c = $this->loggedIn();
        $d = $this->today($c);
        self::assertSame('onboarding', $d['type']);
        self::assertSame(14, $d['program']['original_weeks']);
        self::assertSame('3V5FV62M51FNCP7MEBFSJWJBAY', $d['program']['id']);
        self::assertSame(404, $c->get('/api/v1/me/program')->status);
    }

    public function testConcurrentStartsCreateOnlyOneRun(): void
    {
        $c = $this->loggedIn();
        $results = $this->parallel(4, function () use ($c): int {
            $client = new \Tests\Support\TestClient($this->makeApp(), false);
            $client->cookies = $c->cookies;
            $client->csrf = $c->csrf;
            return $client->post('/api/v1/me/program/start', ['start_mode' => 'beginning'])->status;
        });
        sort($results);
        self::assertSame([201, 409, 409, 409], $results);
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM user_programs'));
        self::assertSame(84, (int) $this->db->value('SELECT COUNT(*) FROM workout_assignments'));
    }
}
