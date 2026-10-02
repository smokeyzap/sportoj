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

/**
 * Lifecycle of regular program workouts: start, complete, skip, reopen (BR-070..BR-088).
 *
 * Every mutation locks the user_programs row first, then re-reads the assignment: this serialises concurrent
 * requests per run and makes repeated requests idempotent (approved interpretation 1).
 */
final class AssignmentService
{
    public function __construct(
        private Db $db,
        private Clock $clock,
        private ProgramRepository $programs,
        private UserProgramRepository $ups,
        private SessionRepository $sessions,
        private ProgressService $progress,
        private NextActionService $next
    ) {
    }

    /** GET /workout-assignments/{id}. @return array<string,mixed> */
    public function get(AuthContext $ctx, string $publicId): array
    {
        $a = $this->owned($ctx, $publicId);
        $template = $this->programs->template((int) $a['template_id']);
        return Presenter::assignmentDetail($a, $template ?? [], $this->programs->exercises((int) $a['template_id']));
    }

    /** @return array<string,mixed> */
    public function start(AuthContext $ctx, string $publicId): array
    {
        $pre = $this->owned($ctx, $publicId);
        return $this->db->transaction(function () use ($ctx, $publicId, $pre): array {
            $up = $this->ups->lock((int) $pre['user_program_id']);
            $a = $this->fresh($publicId);

            if ($a['status'] === 'started') {
                // BR-071: idempotent, return the existing active session.
                return $this->payload($ctx, $a, $this->sessions->startedForAssignment((int) $a['id']));
            }
            $this->assertStartable($up, $a);
            $sessionId = $this->begin($ctx, $up, $a);
            return $this->payload($ctx, $this->fresh($publicId), $this->sessions->byId($sessionId));
        });
    }

    /** @return array<string,mixed> */
    public function complete(AuthContext $ctx, string $publicId, ?string $notes): array
    {
        $pre = $this->owned($ctx, $publicId);
        return $this->db->transaction(function () use ($ctx, $publicId, $pre, $notes): array {
            $up = $this->ups->lock((int) $pre['user_program_id']);
            $a = $this->fresh($publicId);

            if ($a['status'] === 'completed') {
                // Idempotent repeat: 200 without any change (no second session, no second history entry).
                return $this->payload($ctx, $a, $this->sessions->latestCompletedForAssignment((int) $a['id']));
            }
            if ($a['status'] === 'started') {
                $session = $this->sessions->startedForAssignment((int) $a['id']);
                $sessionId = $session !== null ? (int) $session['id'] : $this->begin($ctx, $up, $a, true);
            } elseif ($a['status'] === 'pending') {
                $this->assertStartable($up, $a);
                $sessionId = $this->begin($ctx, $up, $a);   // BR-072: implicit start
            } else {
                throw $this->invalidState('Een ' . $a['status'] . ' training kan niet worden afgerond.');
            }
            $session = $this->sessions->byId($sessionId);
            $now = $this->clock->now();

            $this->ups->setAssignmentState((int) $a['id'], 'completed', $a['started_at'] ? Clock::fromDb((string) $a['started_at']) : $now, $now, null);
            $this->sessions->complete($sessionId, $notes, $now);

            $this->applyContinuationRules($up, $a, $session);
            $this->progress->reconcile((int) $up['id']);
            return $this->payload($ctx, $this->fresh($publicId), $this->sessions->byId($sessionId));
        });
    }

    /** @return array<string,mixed> */
    public function skip(AuthContext $ctx, string $publicId): array
    {
        $pre = $this->owned($ctx, $publicId);
        return $this->db->transaction(function () use ($ctx, $publicId, $pre): array {
            $up = $this->ups->lock((int) $pre['user_program_id']);
            $a = $this->fresh($publicId);

            if ($a['status'] === 'skipped') {
                return $this->payload($ctx, $a, null);
            }
            if (!in_array($a['status'], ['pending', 'started'], true)) {
                throw $this->invalidState('Een ' . $a['status'] . ' training kan niet worden overgeslagen; heropen hem eerst.');
            }
            if ($a['block_status'] !== 'active') {
                throw $this->invalidState('Alleen trainingen uit het actieve blok kunnen worden overgeslagen.');
            }
            $now = $this->clock->now();
            if ($a['status'] === 'started') {
                // BR-083: skip on started cancels the active session transactionally.
                $this->sessions->cancelStartedForAssignment((int) $a['id'], $now);
            }
            $this->ups->setAssignmentState((int) $a['id'], 'skipped', null, null, $now);
            $this->progress->reconcile((int) $up['id']);
            return $this->payload($ctx, $this->fresh($publicId), null);
        });
    }

    /** @return array<string,mixed> */
    public function reopen(AuthContext $ctx, string $publicId): array
    {
        $pre = $this->owned($ctx, $publicId);
        return $this->db->transaction(function () use ($ctx, $publicId, $pre): array {
            $up = $this->ups->lock((int) $pre['user_program_id']);
            $a = $this->fresh($publicId);

            if ($a['status'] === 'pending') {
                return $this->payload($ctx, $a, null);
            }
            if (!in_array($a['status'], ['completed', 'skipped'], true)) {
                throw $this->invalidState('Een ' . $a['status'] . ' training kan niet worden heropend.');
            }
            // Approved interpretation 4: only while the block is still open for work.
            if (!in_array($a['block_status'], ['active', 'decision_required'], true)) {
                throw $this->invalidState('Trainingen in een afgerond of nog niet gestart blok kunnen niet worden heropend.');
            }
            $now = $this->clock->now();
            if ($a['status'] === 'completed') {
                $this->sessions->cancelCompletedForAssignment((int) $a['id'], $now);   // correction, no hard delete (BR-086/087)
            }
            $this->ups->setAssignmentState((int) $a['id'], 'pending', null, null, null);

            $isAnchor = $up['continuation_anchor_assignment_public_id'] === $a['public_id'];
            $isSource = $up['continuation_source_assignment_public_id'] === $a['public_id'];
            if ($isAnchor || $isSource) {
                $this->ups->setContinuation(
                    (int) $up['id'],
                    RecommendationEngine::PROGRAM_SEQUENCE,
                    $isSource ? false : (int) $up['continuation_decision_required'] === 1,
                    null,
                    $isSource ? null : $up['continuation_source_assignment_public_id']
                );
            }
            $this->progress->reconcile((int) $up['id']);
            return $this->payload($ctx, $this->fresh($publicId), null);
        });
    }

    /**
     * BR-054, BR-059, BR-060 on completion of a program workout.
     *
     * @param array<string,mixed> $up locked run row (before this completion)
     * @param array<string,mixed> $a
     * @param array<string,mixed> $session
     */
    private function applyContinuationRules(array $up, array $a, array $session): void
    {
        $remaining = 0;
        foreach ($this->ups->assignmentsForBlock((int) $a['user_program_block_id']) as $x) {
            if (!RecommendationEngine::isTerminal($x)) {
                $remaining++;
            }
        }
        if ($remaining === 0) {
            return;   // block done; reconcile() clears continuation state and raises the block decision
        }
        $recommended = (int) $session['started_as_recommended'] === 1;
        $mode = (string) $up['continuation_mode'];
        $open = (int) $up['continuation_decision_required'] === 1;
        $anchor = $up['continuation_anchor_assignment_public_id'];
        $source = $up['continuation_source_assignment_public_id'];

        if (!$recommended) {
            // Deviation: ask which line to continue on. With a decision already open the new completion replaces the source (interpretation 3).
            $this->ups->setContinuation((int) $up['id'], $mode, true, $anchor, $a['public_id']);
        } elseif ($mode === RecommendationEngine::LAST_WORKOUT_SEQUENCE) {
            $this->ups->setContinuation((int) $up['id'], $mode, $open, $a['public_id'], $source);   // BR-059: anchor moves along
        }
    }

    /**
     * Whether the assignment would be the recommendation at this moment, ignoring an open decision flag.
     *
     * @param array<string,mixed> $up
     * @param array<string,mixed> $a
     */
    private function isRecommended(array $up, array $a): bool
    {
        $rec = RecommendationEngine::recommend(
            $this->ups->assignmentsForBlock((int) $a['user_program_block_id']),
            (string) $up['continuation_mode'],
            $up['continuation_anchor_assignment_public_id']
        );
        return $rec['assignment'] !== null && $rec['assignment']['public_id'] === $a['public_id'];
    }

    /**
     * @param array<string,mixed> $up
     * @param array<string,mixed> $a
     */
    private function assertStartable(array $up, array $a): void
    {
        if ($a['status'] !== 'pending') {
            throw $this->invalidState('Een ' . $a['status'] . ' training kan niet worden gestart.');
        }
        if ($a['block_status'] !== 'active') {
            throw $this->invalidState('Trainingen uit een nog niet actief blok kunnen niet als programmatraining worden gestart.');   // BR-051
        }
        $started = $this->ups->startedAssignment((int) $up['id']);
        if ($started !== null) {
            throw ApiException::conflict('ACTIVE_WORKOUT_EXISTS', 'Er is al een programmatraining bezig.');   // BR-035
        }
    }

    /**
     * Pending -> started plus a program session; returns the session id.
     *
     * @param array<string,mixed> $up
     * @param array<string,mixed> $a
     */
    private function begin(AuthContext $ctx, array $up, array $a, bool $repair = false): int
    {
        $now = $this->clock->now();
        $recommended = $this->isRecommended($up, $a);
        if (!$repair) {
            $this->ups->setAssignmentState((int) $a['id'], 'started', $now, null, null);
        }
        return $this->sessions->createProgramSession($ctx->userId, (int) $up['id'], (int) $a['id'], (int) $a['template_id'], $recommended, $now);
    }

    /**
     * @param array<string,mixed> $a fresh assignment row
     * @param array<string,mixed>|null $session
     * @return array<string,mixed>
     */
    private function payload(AuthContext $ctx, array $a, ?array $session): array
    {
        return [
            'assignment' => Presenter::assignment($a),
            'session' => $session === null ? null : Presenter::session($session),
            'next_action' => $this->next->forUser($ctx->userId),
        ];
    }

    /** @return array<string,mixed> assignment owned by the caller (403 / 404 otherwise) */
    private function owned(AuthContext $ctx, string $publicId): array
    {
        $a = Ulid::isValid($publicId) ? $this->ups->assignmentByPublicId($publicId) : null;
        if ($a === null) {
            throw ApiException::notFound('ASSIGNMENT_NOT_FOUND', 'Training niet gevonden.');
        }
        if ((int) $a['user_id'] !== $ctx->userId) {
            throw ApiException::forbidden();   // approved interpretation 6
        }
        return $a;
    }

    /** @return array<string,mixed> */
    private function fresh(string $publicId): array
    {
        $a = $this->ups->assignmentByPublicId($publicId);
        if ($a === null) {
            throw new \LogicException('assignment vanished');
        }
        return $a;
    }

    private function invalidState(string $message): ApiException
    {
        return ApiException::conflict('INVALID_ASSIGNMENT_STATE', $message);
    }
}
