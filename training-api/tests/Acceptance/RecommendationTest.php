<?php
declare(strict_types=1);

namespace Tests\Acceptance;

use App\Support\Clock;
use Tests\Support\ApiTestCase;

/** AT-030..AT-034, BR-030..BR-043. */
final class RecommendationTest extends ApiTestCase
{
    private function position(array $today): array
    {
        $a = $today['assignment'];
        return [$a['block']['sequence'], $a['cycle'], $a['position']];
    }

    public function testAT030CalendarDaysAreIgnored(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $this->complete($c, $this->aid($c, 1, 1, 1));

        Clock::freeze(new \DateTimeImmutable('+12 days', new \DateTimeZone('UTC')));
        $today = $this->today($c);
        self::assertSame('workout', $today['type']);
        self::assertSame([1, 1, 2], $this->position($today));
        self::assertSame('dinsdag', $today['assignment']['day_label']);
        self::assertSame('pending', $today['assignment']['status']);
        // BR-025: nothing was silently skipped because days passed
        self::assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM workout_assignments WHERE status = 'skipped'"));
    }

    public function testAT031CompletedAreSkipped(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $this->complete($c, $this->aid($c, 1, 1, 1));
        $this->complete($c, $this->aid($c, 1, 1, 2));
        self::assertSame([1, 1, 3], $this->position($this->today($c)));
    }

    public function testAT032SkippedAreSkipped(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $this->complete($c, $this->aid($c, 1, 1, 1));
        $this->skipA($c, $this->aid($c, 1, 1, 2));
        self::assertSame([1, 1, 3], $this->position($this->today($c)));
    }

    public function testAT033StartedHasPriorityAsResume(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $this->complete($c, $this->aid($c, 1, 1, 1));
        $this->complete($c, $this->aid($c, 1, 1, 2));
        $wed = $this->aid($c, 1, 1, 3);
        self::assertSame(200, $this->act($c, $wed, 'start')->status);

        $today = $this->today($c);
        self::assertSame('resume_workout', $today['type']);
        self::assertSame($wed, $today['assignment']['id']);
        self::assertSame('started', $today['assignment']['status']);
        self::assertSame('woensdag', $today['assignment']['day_label']);
        self::assertNotSame('', $today['reason']);
    }

    public function testAT034OnlyOneStartedProgramAssignment(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $this->act($c, $this->aid($c, 1, 1, 1), 'start');
        $r = $this->act($c, $this->aid($c, 1, 1, 4), 'start');
        self::assertSame(409, $r->status);
        self::assertSame('ACTIVE_WORKOUT_EXISTS', $r->errorCode());
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM workout_assignments WHERE status = 'started'"));
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM workout_sessions"));
    }

    public function testCompleteOfAnotherAssignmentWhileOneIsStartedIsAlsoRefused(): void
    {
        // BR-035 must not be bypassable through the implicit start of complete (BR-072).
        $c = $this->loggedIn();
        $this->startProgram($c);
        $this->act($c, $this->aid($c, 1, 1, 1), 'start');
        $r = $this->act($c, $this->aid($c, 1, 1, 3), 'complete');
        self::assertSame(409, $r->status);
        self::assertSame('ACTIVE_WORKOUT_EXISTS', $r->errorCode());
        self::assertSame('pending', $this->statusOf($c, $this->aid($c, 1, 1, 3)));
    }

    public function testNextWorkoutFollowsCycleThenPositionOrder(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $this->completeCycle($c, 1, 1);
        self::assertSame([1, 2, 1], $this->position($this->today($c)));
        self::assertSame(2, $this->today($c)['progress']['cycle']);
    }

    public function testProgressNumbers(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $this->complete($c, $this->aid($c, 1, 1, 1));
        $this->complete($c, $this->aid($c, 1, 1, 2));
        $this->skipA($c, $this->aid($c, 1, 1, 3));
        self::assertSame(
            ['block_number' => 1, 'block_count' => 5, 'cycle' => 1, 'target_cycles' => 3, 'processed' => 3, 'total' => 18, 'completed' => 2, 'skipped' => 1, 'pending' => 15],
            $this->today($c)['progress']
        );
    }

    public function testWorkoutActionCarriesProgramWeekAndWorkoutSummary(): void
    {
        $c = $this->loggedIn();
        $d = $this->startProgram($c)->data();
        self::assertSame(1, $d['assignment']['program_week']);
        self::assertSame('maandag', $d['assignment']['day_label']);
        self::assertSame('Fast and Sweaty', $d['assignment']['workout']['name']);
        self::assertSame('full_body_hiit', $d['assignment']['workout']['category']);
        self::assertSame('amrap', $d['assignment']['workout']['protocol_type']);
        self::assertSame('https://youtu.be/kNgPYQz8mZA', $d['assignment']['workout']['video_url']);
        self::assertFalse($d['assignment']['is_extra_cycle']);
        self::assertSame('0J6147BJFBEFFJK2CH4114053K', $d['assignment']['block']['id']);
    }

    public function testStatelessMutationsDoNotDependOnWhoIsAskingTodayTwice(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        self::assertSame($this->today($c), $this->today($c), 'GET /me/today is a pure read');
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM workout_sessions'));
    }
}
