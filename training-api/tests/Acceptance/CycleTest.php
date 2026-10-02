<?php
declare(strict_types=1);

namespace Tests\Acceptance;

use Tests\Support\ApiTestCase;

/** AT-080..AT-082, BR-100..BR-102. */
final class CycleTest extends ApiTestCase
{
    public function testAT080FinishedCycleContinuesAutomaticallyInTheNextOne(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $this->completeCycle($c, 1, 1, [1, 2, 3, 4, 5]);
        self::assertSame([1, 6], $this->cp($c));
        $r = $this->complete($c, $this->aid($c, 1, 1, 6));
        self::assertSame('workout', $r->at('data.next_action.type'), 'no extra user decision between normal cycles (BR-102)');
        self::assertSame([2, 1], [$r->at('data.next_action.assignment.cycle'), $r->at('data.next_action.assignment.position')]);
        self::assertSame(2, $r->at('data.next_action.assignment.program_week'));
        self::assertSame('active', $this->programState($c)['blocks'][0]['block']['status']);
    }

    /** @return array{int,int} */
    private function cp(\Tests\Support\TestClient $c): array
    {
        $a = $this->today($c)['assignment'];
        return [$a['cycle'], $a['position']];
    }

    public function testAT081NextCycleDoesNotStartWhileAnOpenPositionRemains(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $this->complete($c, $this->aid($c, 1, 1, 1));
        $this->complete($c, $this->aid($c, 1, 1, 2));
        foreach ([4, 5, 6] as $p) {
            $this->complete($c, $this->aid($c, 1, 1, $p));
            $this->db->exec('SELECT 1');
            $c->post('/api/v1/me/program/continuation', ['mode' => 'program_sequence']);   // answer the deviation question
        }
        self::assertSame([1, 3], $this->cp($c), 'Wednesday of cycle 1 is still the oldest open assignment');
        self::assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM workout_assignments WHERE cycle_number = 2 AND status <> 'pending'"));
    }

    public function testAT082PriorToStartCountsAsProcessed(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c, 'position', 6, 4);
        foreach ([4, 5, 6] as $p) {
            $r = $this->complete($c, $this->aid($c, 2, 3, $p));
        }
        $next = $r->at('data.next_action');
        self::assertSame('block_decision', $next['type'], 'cycles 1-2 and positions 1-3 of cycle 3 are prior_to_start, hence processed');
        self::assertSame(['completed' => 3, 'skipped' => 0, 'cycles_processed' => 3], $next['summary'], 'prior_to_start is not counted as completed');
        self::assertSame(['extend', 'advance'], $next['options']);
    }

    public function testBlocksFollowTheDefaultCycleCounts(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $counts = array_map(static fn ($b) => [$b['block']['default_cycle_count'], $b['block']['target_cycles'], count($b['cycles'])], $this->programState($c)['blocks']);
        self::assertSame([[3, 3, 3], [3, 3, 3], [3, 3, 3], [3, 3, 3], [2, 2, 2]], $counts);
        foreach ($this->programState($c)['blocks'] as $b) {
            foreach ($b['cycles'] as $cy) {
                self::assertFalse($cy['is_extra']);
                self::assertSame(['maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag'], array_column(array_column($cy['assignments'], null), 'day_label'));
            }
        }
    }

    public function testProgramWeeksMapToOriginalWeeks(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $weeks = [];
        foreach ($this->programState($c)['blocks'] as $b) {
            foreach ($b['cycles'] as $cy) {
                $weeks[] = $cy['assignments'][0]['program_week'];
            }
        }
        self::assertSame(range(1, 14), $weeks);
    }
}
