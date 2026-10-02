<?php
declare(strict_types=1);

namespace Tests\Acceptance;

use Tests\Support\ApiTestCase;

/** AT-040..AT-045, BR-070..BR-075. */
final class LifecycleTest extends ApiTestCase
{
    public function testAT040StartMovesPendingToStartedWithOneProgramSession(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $id = $this->aid($c, 1, 1, 1);
        $r = $this->act($c, $id, 'start');

        self::assertSame(200, $r->status);
        self::assertSame('started', $r->at('data.assignment.status'));
        self::assertSame('program', $r->at('data.session.session_type'));
        self::assertSame('started', $r->at('data.session.status'));
        self::assertSame($id, $r->at('data.session.assignment_id'));
        self::assertSame('resume_workout', $r->at('data.next_action.type'));
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM workout_sessions'));
        self::assertSame(1, (int) $this->db->value('SELECT started_as_recommended FROM workout_sessions'), 'BR-053');
        self::assertNotNull($this->db->value('SELECT started_at FROM workout_assignments WHERE status = "started"'));
    }

    public function testAT041StartTwiceReturnsSameSession(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $id = $this->aid($c, 1, 1, 1);
        $first = $this->act($c, $id, 'start');
        $second = $this->act($c, $id, 'start');

        self::assertSame(200, $second->status);
        self::assertSame($first->at('data.session.id'), $second->at('data.session.id'));
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM workout_sessions'));
    }

    public function testAT042CompleteNormally(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $id = $this->aid($c, 1, 1, 1);
        $this->act($c, $id, 'start');
        $r = $this->act($c, $id, 'complete');

        self::assertSame(200, $r->status);
        self::assertSame('completed', $r->at('data.assignment.status'));
        self::assertSame('completed', $r->at('data.session.status'));
        self::assertNotNull($r->at('data.session.completed_at'));
        self::assertSame('workout', $r->at('data.next_action.type'));
        self::assertSame(2, $r->at('data.next_action.assignment.position'));
        $row = $this->rowOf('SELECT status, completed_at FROM workout_assignments WHERE public_id = ?', [$id]);
        self::assertSame('completed', $row['status']);
        self::assertNotNull($row['completed_at']);
    }

    public function testAT043CompleteDirectlyFromPending(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $r = $this->act($c, $this->aid($c, 1, 1, 1), 'complete');
        self::assertSame(200, $r->status);
        self::assertSame('completed', $r->at('data.assignment.status'));
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM workout_sessions'));
        self::assertSame(1, (int) $this->db->value("SELECT started_as_recommended FROM workout_sessions"), 'BR-072: recommendation still registered correctly');
        self::assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM workout_assignments WHERE status = 'started'"));
    }

    public function testAT043DirectCompleteOfNonRecommendedRegistersNotRecommended(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $r = $this->act($c, $this->aid($c, 1, 1, 4), 'complete');
        self::assertSame('continuation_decision', $r->at('data.next_action.type'));
        self::assertSame(0, (int) $this->db->value('SELECT started_as_recommended FROM workout_sessions'));
    }

    public function testAT044DoubleCompleteIsIdempotent(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $id = $this->aid($c, 1, 1, 1);
        $first = $this->act($c, $id, 'complete', ['notes' => 'eerste']);
        $second = $this->act($c, $id, 'complete', ['notes' => 'tweede']);

        self::assertSame(200, $second->status);
        self::assertSame($first->at('data.session.id'), $second->at('data.session.id'));
        self::assertSame('eerste', $second->at('data.session.notes'), 'a repeat changes nothing');
        self::assertSame($first->at('data.session.completed_at'), $second->at('data.session.completed_at'));
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM workout_sessions'));
        self::assertSame(1, $c->get('/api/v1/me/history')->at('meta.total'));
        self::assertSame(2, $second->at('data.next_action.assignment.position'), 'recommendation unaffected by the repeat');
    }

    public function testAT045NotesAreStoredAndReturnedViaHistory(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $this->complete($c, $this->aid($c, 1, 1, 1), 'Zwaar maar goed. Ééntje met ü en 🙂');
        $item = $c->get('/api/v1/me/history')->at('data.0');
        self::assertSame('Zwaar maar goed. Ééntje met ü en 🙂', $item['notes']);
        self::assertSame('Zwaar maar goed. Ééntje met ü en 🙂', $this->db->value('SELECT notes FROM workout_sessions'));
    }

    public function testNotesValidation(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $id = $this->aid($c, 1, 1, 1);
        self::assertSame('VALIDATION_ERROR', $this->act($c, $id, 'complete', ['notes' => str_repeat('x', 5001)])->errorCode());
        self::assertSame('VALIDATION_ERROR', $this->act($c, $id, 'complete', ['notes' => ['a']])->errorCode());
        self::assertSame('pending', $this->statusOf($c, $id), 'validation failure leaves no trace');
        self::assertSame(200, $this->act($c, $id, 'complete', ['notes' => str_repeat('x', 5000)])->status);
    }

    public function testCompleteWithNullNotesAndEmptyBody(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        self::assertSame(200, $this->act($c, $this->aid($c, 1, 1, 1), 'complete', ['notes' => null])->status);
        self::assertNull($c->get('/api/v1/me/history')->at('data.0.notes'));
    }

    public function testStartedAsRecommendedIsFalseForDeviatingWorkout(): void
    {
        // AT-050
        $c = $this->loggedIn();
        $this->startProgram($c);
        $this->complete($c, $this->aid($c, 1, 1, 1));
        self::assertSame(2, $this->today($c)['assignment']['position']);
        $this->act($c, $this->aid($c, 1, 1, 4), 'start');
        self::assertSame(0, (int) $this->db->value("SELECT started_as_recommended FROM workout_sessions WHERE status = 'started'"));
    }

    public function testInvalidTransitionsGive409(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c, 'position', 6, 4);   // block 2, cycle 3, from position 4
        $prior = $this->aid($c, 2, 3, 1);
        $future = $this->aid($c, 3, 1, 1);
        $completed = $this->aid($c, 2, 3, 4);
        $skipped = $this->aid($c, 2, 3, 5);
        $this->complete($c, $completed);
        $this->skipA($c, $skipped);

        foreach ([
            [$prior, 'start'], [$prior, 'complete'], [$prior, 'skip'], [$prior, 'reopen'],
            [$future, 'start'], [$future, 'complete'], [$future, 'skip'],
            [$completed, 'start'], [$completed, 'skip'],
            [$skipped, 'start'], [$skipped, 'complete'],
        ] as [$id, $action]) {
            $r = $this->act($c, $id, $action);
            self::assertSame(409, $r->status, "$action on " . $this->statusOf($c, $id));
            self::assertSame('INVALID_ASSIGNMENT_STATE', $r->errorCode());
        }
        self::assertSame('prior_to_start', $this->statusOf($c, $prior), 'BR-024: prior_to_start only ever set at start');
        self::assertSame('pending', $this->statusOf($c, $future));
    }

    public function testBR007FutureBlockAssignmentsAreVisibleButLocked(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $future = $this->aid($c, 2, 1, 1);
        $a = $this->assignment($c, $future);
        self::assertSame('pending', $a['status']);
        self::assertSame('not_started', $a['block']['status']);
        self::assertSame(409, $this->act($c, $future, 'start')->status);
    }

    public function testBR050ChoosingAnotherPendingAssignmentInTheActiveBlockIsAllowed(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        self::assertSame(200, $this->act($c, $this->aid($c, 1, 2, 3), 'start')->status, 'a later cycle of the active block is selectable');
    }

    public function testAssignmentDetail(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $a = $this->assignment($c, $this->aid($c, 1, 1, 1));
        self::assertSame('Fast and Sweaty', $a['workout_detail']['name']);
        self::assertSame(['duration_seconds' => 600, 'round_rest_seconds' => 60], $a['workout_detail']['protocol_config']);
        self::assertCount(5, $a['workout_detail']['exercises']);
        self::assertSame('jumping jacks', $a['workout_detail']['exercises'][0]['name']);
        self::assertSame(20, $a['workout_detail']['exercises'][0]['reps']);
        self::assertNull($a['workout_detail']['exercises'][0]['sets']);
        self::assertNull($a['started_at']);

        // empty protocol_config must stay a JSON object, not []
        $leg = $c->get('/api/v1/workout-assignments/' . $this->aid($c, 1, 1, 2));
        self::assertStringContainsString('"protocol_config":{}', (string) $leg->body);
    }

    public function testNextActionAfterLastAssignmentIsBlockDecision(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c, 'position', 3, 6);   // only block 1 / cycle 3 / position 6 left in block 1
        $r = $this->complete($c, $this->aid($c, 1, 3, 6));
        self::assertSame('block_decision', $r->at('data.next_action.type'));
    }

    public function testTimestampsAreIsoUtc(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $r = $this->complete($c, $this->aid($c, 1, 1, 1));
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $r->at('data.session.completed_at'));
    }
}
