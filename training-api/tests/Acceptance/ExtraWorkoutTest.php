<?php
declare(strict_types=1);

namespace Tests\Acceptance;

use Tests\Support\ApiTestCase;

/** AT-070..AT-073, BR-090..BR-093, interpretation 2. */
final class ExtraWorkoutTest extends ApiTestCase
{
    private function templateId(\Tests\Support\TestClient $c, int $block, int $pos): string
    {
        return $this->assignment($c, $this->aid($c, $block, 1, $pos))['workout']['id'];
    }

    public function testGetWorkoutDetail(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $id = $this->templateId($c, 1, 1);
        $r = $c->get("/api/v1/workouts/$id");
        self::assertSame(200, $r->status);
        self::assertSame($id, $r->at('data.id'));
        self::assertSame('Fast and Sweaty', $r->at('data.name'));
        self::assertCount(5, $r->at('data.exercises'));
        self::assertSame(['duration_seconds' => 600, 'round_rest_seconds' => 60], $r->at('data.protocol_config'));

        self::assertSame('WORKOUT_NOT_FOUND', $c->get('/api/v1/workouts/01ARZ3NDEKTSV4RRFFQ69G5FAV')->errorCode());
        self::assertSame('WORKOUT_NOT_FOUND', $c->get('/api/v1/workouts/12345')->errorCode());
    }

    public function testAT070StartExtraWorkout(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $r = $c->post('/api/v1/workouts/' . $this->templateId($c, 1, 2) . '/sessions');
        self::assertSame(201, $r->status);
        self::assertSame('extra', $r->at('data.session_type'));
        self::assertSame('started', $r->at('data.status'));
        self::assertNull($r->at('data.assignment_id'));
        self::assertSame('Leg Day Love', $r->at('data.workout.name'));
        $row = $this->rowOf('SELECT workout_assignment_id, session_type, started_as_recommended FROM workout_sessions');
        self::assertSame([null, 'extra', null], array_values($row));
    }

    public function testInterpretation2SecondStartOnSameTemplateReturnsTheExistingSession(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $tpl = $this->templateId($c, 1, 2);
        $a = $c->post("/api/v1/workouts/$tpl/sessions");
        $b = $c->post("/api/v1/workouts/$tpl/sessions");
        self::assertSame($a->at('data.id'), $b->at('data.id'));
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM workout_sessions'));
        // a different template is a different session
        $c->post('/api/v1/workouts/' . $this->templateId($c, 1, 3) . '/sessions');
        self::assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM workout_sessions'));
        // after completion the same workout may be done again as a new extra session
        $c->post('/api/v1/workout-sessions/' . $a->at('data.id') . '/complete');
        $again = $c->post("/api/v1/workouts/$tpl/sessions");
        self::assertNotSame($a->at('data.id'), $again->at('data.id'));
    }

    public function testAT071CompleteExtraAppearsInHistory(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $s = $c->post('/api/v1/workouts/' . $this->templateId($c, 1, 2) . '/sessions')->at('data.id');
        $r = $c->post("/api/v1/workout-sessions/$s/complete", ['notes' => 'extra gedaan']);
        self::assertSame(200, $r->status);
        self::assertSame('completed', $r->at('data.status'));
        self::assertNotNull($r->at('data.completed_at'));
        self::assertSame('extra gedaan', $r->at('data.notes'));

        $h = $c->get('/api/v1/me/history')->at('data');
        self::assertCount(1, $h);
        self::assertSame('extra_completed', $h[0]['event_type']);
        self::assertNull($h[0]['block']);
        self::assertNull($h[0]['cycle']);
        self::assertNull($h[0]['assignment_id']);
        self::assertSame($s, $h[0]['session_id']);
        self::assertSame('extra gedaan', $h[0]['notes']);
    }

    public function testCompleteExtraIsIdempotent(): void
    {
        $c = $this->loggedIn();
        $s = $c->post('/api/v1/workouts/' . $this->extraTemplate($c) . '/sessions')->at('data.id');
        $a = $c->post("/api/v1/workout-sessions/$s/complete", ['notes' => 'x']);
        $b = $c->post("/api/v1/workout-sessions/$s/complete", ['notes' => 'y']);
        self::assertSame(200, $b->status);
        self::assertSame($a->at('data.completed_at'), $b->at('data.completed_at'));
        self::assertSame('x', $b->at('data.notes'));
        self::assertSame(1, $c->get('/api/v1/me/history')->at('meta.total'));
    }

    private function extraTemplate(\Tests\Support\TestClient $c): string
    {
        return (string) $this->db->value('SELECT public_id FROM workout_templates ORDER BY id LIMIT 1');
    }

    public function testExtraWorkoutWorksWithoutAnyProgram(): void
    {
        $c = $this->loggedIn();
        $r = $c->post('/api/v1/workouts/' . $this->extraTemplate($c) . '/sessions');
        self::assertSame(201, $r->status);
        self::assertNull($this->db->value('SELECT user_program_id FROM workout_sessions'));
    }

    public function testAT072ExtraWorkoutDoesNotTouchProgress(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $this->complete($c, $this->aid($c, 1, 1, 1));
        $this->skipA($c, $this->aid($c, 1, 1, 2));
        $beforeState = $c->get('/api/v1/me/program')->body;
        $beforeToday = $c->get('/api/v1/me/today')->body;
        $beforeAssignments = $this->db->all('SELECT id, status, started_at, completed_at, skipped_at, updated_at FROM workout_assignments ORDER BY id');
        $beforeProgram = $this->rowOf('SELECT continuation_mode, continuation_decision_required, continuation_anchor_assignment_public_id, continuation_source_assignment_public_id FROM user_programs');

        // the very workout that is recommended next, done as an extra, plus a future-block one
        $recommended = $this->templateId($c, 1, 3);
        $s = $c->post("/api/v1/workouts/$recommended/sessions")->at('data.id');
        $c->post("/api/v1/workout-sessions/$s/complete");
        $s2 = $c->post('/api/v1/workouts/' . $this->templateId($c, 4, 1) . '/sessions')->at('data.id');
        $c->post("/api/v1/workout-sessions/$s2/complete");

        self::assertSame($beforeState, $c->get('/api/v1/me/program')->body);
        self::assertSame($beforeToday, $c->get('/api/v1/me/today')->body);
        self::assertSame($beforeAssignments, $this->db->all('SELECT id, status, started_at, completed_at, skipped_at, updated_at FROM workout_assignments ORDER BY id'));
        self::assertSame($beforeProgram, $this->rowOf('SELECT continuation_mode, continuation_decision_required, continuation_anchor_assignment_public_id, continuation_source_assignment_public_id FROM user_programs'));
    }

    public function testExtraWorkoutDoesNotBlockProgramWorkouts(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $c->post('/api/v1/workouts/' . $this->templateId($c, 1, 3) . '/sessions');
        self::assertSame(200, $this->act($c, $this->aid($c, 1, 1, 1), 'start')->status, 'BR-033 only counts regular assignments');
        self::assertSame('resume_workout', $this->today($c)['type']);
    }

    public function testAT073FutureBlockWorkoutAsExtraButAssignmentStaysLocked(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $future = $this->aid($c, 3, 1, 1);
        $tpl = $this->assignment($c, $future)['workout']['id'];

        $r = $c->post("/api/v1/workouts/$tpl/sessions");
        self::assertSame(201, $r->status);
        self::assertSame('extra', $r->at('data.session_type'));
        $c->post('/api/v1/workout-sessions/' . $r->at('data.id') . '/complete');

        $a = $this->assignment($c, $future);
        self::assertSame('pending', $a['status']);
        self::assertSame('not_started', $a['block']['status']);
        self::assertSame(409, $this->act($c, $future, 'start')->status);
    }

    public function testProgramSessionCannotBeCompletedViaSessionEndpoint(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $session = $this->act($c, $this->aid($c, 1, 1, 1), 'start')->at('data.session.id');
        $r = $c->post("/api/v1/workout-sessions/$session/complete");
        self::assertSame(409, $r->status);
        self::assertSame('INVALID_SESSION_STATE', $r->errorCode());
        self::assertSame('started', $this->db->value('SELECT status FROM workout_sessions'));
    }

    public function testCancelledSessionCannotBeCompleted(): void
    {
        $c = $this->loggedIn();
        $s = $c->post('/api/v1/workouts/' . $this->extraTemplate($c) . '/sessions')->at('data.id');
        $this->db->exec("UPDATE workout_sessions SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP(6)");
        $r = $c->post("/api/v1/workout-sessions/$s/complete");
        self::assertSame(409, $r->status);
        self::assertSame('INVALID_SESSION_STATE', $r->errorCode());
    }

    public function testSessionNotesValidation(): void
    {
        $c = $this->loggedIn();
        $s = $c->post('/api/v1/workouts/' . $this->extraTemplate($c) . '/sessions')->at('data.id');
        self::assertSame('VALIDATION_ERROR', $c->post("/api/v1/workout-sessions/$s/complete", ['notes' => str_repeat('x', 5001)])->errorCode());
        self::assertSame('VALIDATION_ERROR', $c->post("/api/v1/workout-sessions/$s/complete", ['notes' => 12])->errorCode());
        self::assertSame('started', $this->db->value('SELECT status FROM workout_sessions'));
        self::assertSame(200, $c->post("/api/v1/workout-sessions/$s/complete")->status, 'notes are optional');
    }

    public function testUnknownSessionIs404(): void
    {
        $c = $this->loggedIn();
        self::assertSame('SESSION_NOT_FOUND', $c->post('/api/v1/workout-sessions/01ARZ3NDEKTSV4RRFFQ69G5FAV/complete')->errorCode());
        self::assertSame('SESSION_NOT_FOUND', $c->post('/api/v1/workout-sessions/abc/complete')->errorCode());
    }

    public function testInactiveTemplateIsNotFound(): void
    {
        $c = $this->loggedIn();
        $tpl = $this->extraTemplate($c);
        $this->db->exec('UPDATE workout_templates SET is_active = 0 WHERE public_id = ?', [$tpl]);
        try {
            self::assertSame('WORKOUT_NOT_FOUND', $c->get("/api/v1/workouts/$tpl")->errorCode());
            self::assertSame('WORKOUT_NOT_FOUND', $c->post("/api/v1/workouts/$tpl/sessions")->errorCode());
        } finally {
            $this->db->exec('UPDATE workout_templates SET is_active = 1 WHERE public_id = ?', [$tpl]);
        }
    }
}
