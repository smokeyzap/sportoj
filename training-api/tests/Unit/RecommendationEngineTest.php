<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RecommendationEngine as E;
use PHPUnit\Framework\TestCase;

final class RecommendationEngineTest extends TestCase
{
    /** @param list<string> $statuses statuses in (cycle, position) order, 6 per cycle */
    private function block(array $statuses): array
    {
        $rows = [];
        foreach ($statuses as $i => $s) {
            $rows[] = ['public_id' => 'A' . $i, 'status' => $s, 'cycle_number' => intdiv($i, 6) + 1, 'sequence' => $i % 6 + 1];
        }
        return $rows;
    }

    private function id(array $r): ?string
    {
        return $r['assignment']['public_id'] ?? null;
    }

    public function testProgramSequencePicksTheFirstPending(): void
    {
        $rows = $this->block(['completed', 'skipped', 'prior_to_start', 'pending', 'pending', 'pending']);
        self::assertSame('A3', $this->id(E::recommend($rows, E::PROGRAM_SEQUENCE, null)));
    }

    public function testNothingPendingGivesNull(): void
    {
        $rows = $this->block(['completed', 'skipped', 'prior_to_start', 'completed', 'completed', 'completed']);
        self::assertNull($this->id(E::recommend($rows, E::PROGRAM_SEQUENCE, null)));
        self::assertNull($this->id(E::recommend($rows, E::LAST_WORKOUT_SEQUENCE, 'A3')));
    }

    public function testStartedAssignmentsAreNotRecommendable(): void
    {
        $rows = $this->block(['started', 'pending', 'pending', 'pending', 'pending', 'pending']);
        self::assertSame('A1', $this->id(E::recommend($rows, E::PROGRAM_SEQUENCE, null)));
    }

    public function testLastWorkoutSequenceContinuesAfterTheAnchor(): void
    {
        $rows = $this->block(['completed', 'pending', 'pending', 'completed', 'pending', 'pending']);
        $r = E::recommend($rows, E::LAST_WORKOUT_SEQUENCE, 'A3');
        self::assertSame('A4', $this->id($r) === 'A4' ? 'A4' : $this->id($r));
        self::assertSame('A4', $this->id(E::recommend($this->block(['completed', 'pending', 'pending', 'completed', 'pending', 'pending']), E::LAST_WORKOUT_SEQUENCE, 'A3')));
        self::assertFalse($r['fell_back']);
        self::assertSame(E::LAST_WORKOUT_SEQUENCE, $r['mode']);
    }

    public function testFallsBackWhenNothingFollowsTheAnchor(): void
    {
        $rows = $this->block(['completed', 'pending', 'pending', 'completed', 'completed', 'completed']);
        $r = E::recommend($rows, E::LAST_WORKOUT_SEQUENCE, 'A5');
        self::assertSame('A1', $this->id($r));
        self::assertTrue($r['fell_back']);
        self::assertSame(E::PROGRAM_SEQUENCE, $r['mode']);
    }

    public function testFallsBackWhenTheAnchorIsUnknown(): void
    {
        $rows = $this->block(['completed', 'pending', 'pending', 'pending', 'pending', 'pending']);
        $r = E::recommend($rows, E::LAST_WORKOUT_SEQUENCE, 'GONE');
        self::assertSame('A1', $this->id($r));
        self::assertTrue($r['fell_back']);
        self::assertTrue(E::recommend($rows, E::LAST_WORKOUT_SEQUENCE, null)['fell_back']);
    }

    public function testAnchorMayLieInAnEarlierCycleThanTheNextPending(): void
    {
        $rows = $this->block(['completed', 'pending', 'completed', 'completed', 'completed', 'completed', 'pending', 'pending', 'pending', 'pending', 'pending', 'pending']);
        self::assertSame('A6', $this->id(E::recommend($rows, E::LAST_WORKOUT_SEQUENCE, 'A5')), 'BR-058: same block order, across cycles');
    }

    public function testCountsAndCyclesProcessed(): void
    {
        $rows = $this->block(['completed', 'skipped', 'prior_to_start', 'completed', 'completed', 'completed', 'completed', 'pending', 'started', 'completed', 'completed', 'completed']);
        self::assertSame(['processed' => 10, 'total' => 12, 'completed' => 8, 'skipped' => 1, 'pending' => 2], E::counts($rows));
        self::assertSame(1, E::cyclesProcessed($rows));
        self::assertSame(2, E::cyclesProcessed($this->block(array_fill(0, 12, 'completed'))));
        self::assertSame(0, E::cyclesProcessed([]));
    }
}
