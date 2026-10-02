<?php
declare(strict_types=1);

namespace Tests\Acceptance;

use Tests\Support\ApiTestCase;
use Tests\Support\TestClient;

/** AT-050..AT-057, BR-050..BR-063 and approved interpretations 3. */
final class ContinuationTest extends ApiTestCase
{
    /** Monday done; Tuesday recommended; user completes Thursday -> open continuation decision. */
    private function deviate(TestClient $c, int $block = 1, int $cycle = 1): void
    {
        $this->complete($c, $this->aid($c, $block, $cycle, 1));
        self::assertSame(2, $this->today($c)['assignment']['position']);
        $this->act($c, $this->aid($c, $block, $cycle, 4), 'start');
        $this->act($c, $this->aid($c, $block, $cycle, 4), 'complete');
    }

    private function choose(TestClient $c, string $mode): \Tests\Support\TestResponse
    {
        return $c->post('/api/v1/me/program/continuation', ['mode' => $mode]);
    }

    /** @return array{int,int} cycle and position of today's workout */
    private function cp(TestClient $c): array
    {
        $a = $this->today($c)['assignment'];
        return [$a['cycle'], $a['position']];
    }

    public function testAT051DeviatingCompletionRaisesContinuationDecision(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $this->complete($c, $this->aid($c, 1, 1, 1));
        $thu = $this->aid($c, 1, 1, 4);
        $this->act($c, $thu, 'start');
        $r = $this->act($c, $thu, 'complete');

        $next = $r->at('data.next_action');
        self::assertSame('continuation_decision', $next['type']);
        self::assertSame($thu, $next['source_assignment']['id']);
        self::assertSame('donderdag', $next['source_assignment']['day_label']);
        self::assertSame('completed', $next['source_assignment']['status']);
        self::assertSame(['program_sequence', 'last_workout_sequence'], $next['options']);
        self::assertSame('pending', $this->statusOf($c, $this->aid($c, 1, 1, 2)));
        self::assertSame('pending', $this->statusOf($c, $this->aid($c, 1, 1, 3)));
        self::assertSame($next, $this->today($c), 'BR-032: decision beats everything else on me/today');
    }

    public function testAT052ChooseProgramSequence(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $this->deviate($c);
        $r = $this->choose($c, 'program_sequence');
        self::assertSame(200, $r->status);
        self::assertSame('workout', $r->at('data.type'));
        self::assertSame(2, $r->at('data.assignment.position'));
        self::assertSame('program_sequence', $this->programState($c)['continuation_mode']);
    }

    public function testAT053ChooseLastWorkoutSequence(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $this->deviate($c);
        $r = $this->choose($c, 'last_workout_sequence');
        self::assertSame(200, $r->status);
        self::assertSame(5, $r->at('data.assignment.position'));
        self::assertSame('vrijdag', $r->at('data.assignment.day_label'));
        self::assertSame('last_workout_sequence', $this->programState($c)['continuation_mode']);
        self::assertSame($this->aid($c, 1, 1, 4), $this->db->value('SELECT continuation_anchor_assignment_public_id FROM user_programs'));
    }

    public function testAT054AnchorMovesAlongWithoutNewDecision(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $this->deviate($c);
        $this->choose($c, 'last_workout_sequence');

        $fri = $this->aid($c, 1, 1, 5);
        $this->act($c, $fri, 'start');
        $r = $this->act($c, $fri, 'complete');
        self::assertSame('workout', $r->at('data.next_action.type'));
        self::assertSame(6, $r->at('data.next_action.assignment.position'));
        self::assertSame(1, (int) $this->db->value('SELECT started_as_recommended FROM workout_sessions WHERE workout_assignment_id = (SELECT id FROM workout_assignments WHERE public_id = ?)', [$fri]));
        self::assertSame($fri, $this->db->value('SELECT continuation_anchor_assignment_public_id FROM user_programs'), 'BR-059');
        self::assertSame(0, (int) $this->db->value('SELECT continuation_decision_required FROM user_programs'));
    }

    public function testAT055EarlierGapsStayPending(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $this->deviate($c);
        $this->choose($c, 'last_workout_sequence');
        $this->complete($c, $this->aid($c, 1, 1, 5));
        $r = $this->complete($c, $this->aid($c, 1, 1, 6));

        self::assertSame('pending', $this->statusOf($c, $this->aid($c, 1, 1, 2)));
        self::assertSame('pending', $this->statusOf($c, $this->aid($c, 1, 1, 3)));
        // BR-061: nothing auto-completed or auto-skipped. The line continues into cycle 2.
        self::assertSame('workout', $r->at('data.next_action.type'));
        self::assertSame([2, 1], [$r->at('data.next_action.assignment.cycle'), $r->at('data.next_action.assignment.position')]);
        self::assertSame('last_workout_sequence', $this->programState($c)['continuation_mode']);
    }

    public function testAT056AutomaticFallbackWhenNothingFollowsTheAnchor(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c, 'position', 3, 1);   // last cycle of block 1: nothing can follow Saturday
        $this->deviate($c, 1, 3);
        $this->choose($c, 'last_workout_sequence');
        $this->complete($c, $this->aid($c, 1, 3, 5));
        $r = $this->complete($c, $this->aid($c, 1, 3, 6));

        self::assertSame('workout', $r->at('data.next_action.type'));
        self::assertSame(2, $r->at('data.next_action.assignment.position'), 'BR-062: back to the oldest open assignment (Tuesday)');
        self::assertSame('program_sequence', $this->programState($c)['continuation_mode']);
        self::assertNull($this->db->value('SELECT continuation_anchor_assignment_public_id FROM user_programs'));
        self::assertSame('pending', $this->statusOf($c, $this->aid($c, 1, 3, 3)));
    }

    public function testFallbackAlsoAppliesImmediatelyWhenChoosingLastWorkoutWithNothingAfterIt(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c, 'position', 3, 1);
        $this->complete($c, $this->aid($c, 1, 3, 1));
        $this->complete($c, $this->aid($c, 1, 3, 6));   // deviation straight to the last position
        self::assertSame('continuation_decision', $this->today($c)['type']);
        $r = $this->choose($c, 'last_workout_sequence');
        self::assertSame(2, $r->at('data.assignment.position'));
        self::assertSame('program_sequence', $this->programState($c)['continuation_mode']);
    }

    public function testAT057DeviatingAgainWhileOnLastWorkoutSequenceAsksAgain(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $this->deviate($c);
        $this->choose($c, 'last_workout_sequence');
        self::assertSame(5, $this->today($c)['assignment']['position']);   // Friday recommended

        $tue = $this->aid($c, 1, 1, 2);
        $this->act($c, $tue, 'start');
        $r = $this->act($c, $tue, 'complete');
        self::assertSame('continuation_decision', $r->at('data.next_action.type'));
        self::assertSame($tue, $r->at('data.next_action.source_assignment.id'));
        self::assertSame(0, (int) $this->db->value('SELECT started_as_recommended FROM workout_sessions WHERE workout_assignment_id = (SELECT id FROM workout_assignments WHERE public_id = ?)', [$tue]));

        $after = $this->choose($c, 'program_sequence');
        self::assertSame(3, $after->at('data.assignment.position'), 'oldest open assignment is now Wednesday');
    }

    public function testInterpretation3DeviatingWhileADecisionIsOpenReplacesTheSource(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $this->deviate($c);
        $thu = $this->aid($c, 1, 1, 4);
        $sat = $this->aid($c, 1, 1, 6);

        $start = $this->act($c, $sat, 'start');
        self::assertSame(200, $start->status, 'starting while a decision is open is allowed');
        self::assertSame('continuation_decision', $this->today($c)['type'], 'BR-032 priority is kept literally');
        $done = $this->act($c, $sat, 'complete');
        self::assertSame($sat, $done->at('data.next_action.source_assignment.id'));
        self::assertNotSame($thu, $done->at('data.next_action.source_assignment.id'));
        self::assertSame(1, (int) $this->db->value('SELECT continuation_decision_required FROM user_programs'));

        $r = $this->choose($c, 'last_workout_sequence');
        self::assertSame([2, 1], [$r->at('data.assignment.cycle'), $r->at('data.assignment.position')], 'anchor is the replaced source (Saturday)');
    }

    public function testChoosingWithoutOpenDecisionIs409(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $r = $this->choose($c, 'program_sequence');
        self::assertSame(409, $r->status);
        self::assertSame('NO_CONTINUATION_DECISION', $r->errorCode());

        $this->deviate($c);
        self::assertSame(200, $this->choose($c, 'program_sequence')->status);
        self::assertSame('NO_CONTINUATION_DECISION', $this->choose($c, 'program_sequence')->errorCode(), 'answering twice');
    }

    public function testChoosingWithoutAnyProgramIs409(): void
    {
        $c = $this->loggedIn();
        self::assertSame('NO_CONTINUATION_DECISION', $this->choose($c, 'program_sequence')->errorCode());
    }

    public function testInvalidModeIsValidationError(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        self::assertSame('VALIDATION_ERROR', $this->choose($c, 'whatever')->errorCode());
        self::assertSame('VALIDATION_ERROR', $c->post('/api/v1/me/program/continuation', [])->errorCode());
        self::assertSame('VALIDATION_ERROR', $c->post('/api/v1/me/program/continuation', ['mode' => 3])->errorCode());
    }

    public function testRecommendedCompletionInProgramSequenceNeverAsks(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        for ($p = 1; $p <= 6; $p++) {
            $r = $this->complete($c, $this->aid($c, 1, 1, $p));
            self::assertNotSame('continuation_decision', $r->at('data.next_action.type'));
        }
    }

    public function testSkipDoesNotCreateADecision(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $r = $this->skipA($c, $this->aid($c, 1, 1, 4));   // not the recommendation, but nothing was *done*
        self::assertSame('workout', $r->at('data.next_action.type'));
        self::assertSame(1, $r->at('data.next_action.assignment.position'));
    }

    public function testAnchorAndEarlierGapsLeaveBlockFinishingRule(): void
    {
        // BR-063: whatever the mode, a block cannot complete while earlier assignments are pending.
        $c = $this->loggedIn();
        $this->startProgram($c, 'position', 3, 1);
        $this->complete($c, $this->aid($c, 1, 3, 1));
        $this->complete($c, $this->aid($c, 1, 3, 4));
        $this->choose($c, 'last_workout_sequence');
        $this->complete($c, $this->aid($c, 1, 3, 5));
        $this->complete($c, $this->aid($c, 1, 3, 6));
        self::assertSame('workout', $this->today($c)['type']);
        self::assertSame('active', $this->programState($c)['blocks'][0]['block']['status']);
        $this->complete($c, $this->aid($c, 1, 3, 2));
        self::assertSame('workout', $this->today($c)['type']);
        $this->complete($c, $this->aid($c, 1, 3, 3));
        self::assertSame('block_decision', $this->today($c)['type']);
    }
}
