<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth\AuthContext;
use App\Http\ApiException;
use App\Presenters\Presenter;
use App\Repositories\ProgramRepository;
use App\Repositories\SessionRepository;
use App\Repositories\UserProgramRepository;
use App\Support\Clock;
use App\Support\Db;
use App\Support\Ulid;

/** Voluntary extra workouts (BR-090..BR-093). They never touch assignments, cycles, blocks or recommendation state. */
final class ExtraWorkoutService
{
    public function __construct(
        private Db $db,
        private Clock $clock,
        private ProgramRepository $programs,
        private UserProgramRepository $ups,
        private SessionRepository $sessions
    ) {
    }

    /** GET /workouts/{id}. @return array<string,mixed> */
    public function workout(string $publicId): array
    {
        $t = Ulid::isValid($publicId) ? $this->programs->activeTemplateByPublicId($publicId) : null;
        if ($t === null) {
            throw ApiException::notFound('WORKOUT_NOT_FOUND', 'Workout niet gevonden.');
        }
        return Presenter::workoutDetail($t, $this->programs->exercises((int) $t['id']));
    }

    /** POST /workouts/{id}/sessions. A second start on the same template returns the existing started session (interpretation 2). */
    public function start(AuthContext $ctx, string $templatePublicId): array
    {
        $t = Ulid::isValid($templatePublicId) ? $this->programs->activeTemplateByPublicId($templatePublicId) : null;
        if ($t === null) {
            throw ApiException::notFound('WORKOUT_NOT_FOUND', 'Workout niet gevonden.');
        }
        return $this->db->transaction(function () use ($ctx, $t): array {
            $this->db->run('SELECT id FROM users WHERE id = ? FOR UPDATE', [$ctx->userId]);
            $existing = $this->sessions->startedExtra($ctx->userId, (int) $t['id']);
            if ($existing !== null) {
                return Presenter::session($existing);
            }
            $run = $this->ups->latestForUser($ctx->userId);
            $runId = $run !== null && in_array($run['status'], ['active', 'paused'], true) ? (int) $run['id'] : null;
            $id = $this->sessions->createExtraSession($ctx->userId, $runId, (int) $t['id'], $this->clock->now());
            return Presenter::session($this->sessions->byId($id) ?? []);
        });
    }

    /** POST /workout-sessions/{id}/complete. */
    public function complete(AuthContext $ctx, string $sessionPublicId, ?string $notes): array
    {
        $pre = Ulid::isValid($sessionPublicId) ? $this->sessions->byPublicId($sessionPublicId) : null;
        if ($pre === null) {
            throw ApiException::notFound('SESSION_NOT_FOUND', 'Sessie niet gevonden.');
        }
        if ((int) $pre['user_id'] !== $ctx->userId) {
            throw ApiException::forbidden();
        }
        return $this->db->transaction(function () use ($sessionPublicId, $notes): array {
            $s = $this->sessions->lockByPublicId($sessionPublicId);
            if ($s === null) {
                throw ApiException::notFound('SESSION_NOT_FOUND', 'Sessie niet gevonden.');
            }
            if ($s['session_type'] !== 'extra') {
                throw ApiException::conflict('INVALID_SESSION_STATE', 'Programmatrainingen worden via hun assignment afgerond.');
            }
            if ($s['status'] === 'completed') {
                return Presenter::session($s);   // idempotent
            }
            if ($s['status'] !== 'started') {
                throw ApiException::conflict('INVALID_SESSION_STATE', 'Deze sessie kan niet meer worden afgerond.');
            }
            $this->sessions->complete((int) $s['id'], $notes, $this->clock->now());
            return Presenter::session($this->sessions->byId((int) $s['id']) ?? []);
        });
    }
}
