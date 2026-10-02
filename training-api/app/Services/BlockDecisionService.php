<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth\AuthContext;
use App\Http\ApiException;
use App\Repositories\ProgramRepository;
use App\Repositories\UserProgramRepository;
use App\Support\Clock;
use App\Support\Db;
use App\Support\Ulid;

/** Continuation decision plus block decisions extend / advance (BR-054..BR-059, BR-110..BR-123). */
final class BlockDecisionService
{
    public function __construct(
        private Db $db,
        private Clock $clock,
        private ProgramRepository $programs,
        private UserProgramRepository $ups,
        private ProgressService $progress,
        private NextActionService $next
    ) {
    }

    /** POST /me/program/continuation. @return array<string,mixed> next action */
    public function chooseContinuation(AuthContext $ctx, string $mode): array
    {
        if (!in_array($mode, [RecommendationEngine::PROGRAM_SEQUENCE, RecommendationEngine::LAST_WORKOUT_SEQUENCE], true)) {
            throw ApiException::validation(['mode' => 'Moet "program_sequence" of "last_workout_sequence" zijn.']);
        }
        $latest = $this->ups->latestForUser($ctx->userId);
        if ($latest === null) {
            throw $this->noDecision();
        }
        $this->db->transaction(function () use ($latest, $mode): void {
            $up = $this->ups->lock((int) $latest['id']);
            if ($up['status'] === 'completed' || (int) $up['continuation_decision_required'] !== 1) {
                throw $this->noDecision();
            }
            if ($mode === RecommendationEngine::PROGRAM_SEQUENCE) {
                // BR-056: back to the oldest open assignment of the active block.
                $this->ups->setContinuation((int) $up['id'], $mode, false, null, null);
            } else {
                // BR-057: the just completed deviating assignment becomes the anchor.
                $this->ups->setContinuation((int) $up['id'], $mode, false, $up['continuation_source_assignment_public_id'], null);
            }
            $this->progress->reconcile((int) $up['id']);   // BR-062 fallback when nothing follows the anchor
        });
        return $this->next->forUser($ctx->userId);
    }

    /** @return array<string,mixed> next action */
    public function extend(AuthContext $ctx, string $blockPublicId): array
    {
        $latest = $this->resolveRun($ctx, $blockPublicId);
        $this->db->transaction(function () use ($latest, $blockPublicId): void {
            $up = $this->ups->lock((int) $latest['id']);
            $block = $this->findBlock((int) $up['id'], $blockPublicId);
            if ($up['status'] === 'completed' || $block['block_status'] !== 'decision_required') {
                throw $this->notReady();
            }
            $newCycle = (int) $block['target_cycles'] + 1;
            $rows = [];
            foreach ($this->programs->blockWorkouts((int) $block['tb_id']) as $bw) {
                $rows[] = ['ubp' => (int) $block['ubp_id'], 'bw' => (int) $bw['id'], 'cycle' => $newCycle, 'seq' => (int) $bw['sequence'], 'status' => 'pending', 'extra' => 1];
            }
            if (count($rows) !== 6) {
                throw new \LogicException('Block ' . $blockPublicId . ' does not have exactly six workouts.');
            }
            $this->ups->insertAssignments($rows);                       // BR-114/115
            $this->ups->setTargetCycles((int) $block['ubp_id'], $newCycle);   // BR-113
            $this->ups->setBlockStatus((int) $block['ubp_id'], 'active');
            $this->ups->setContinuation((int) $up['id'], RecommendationEngine::PROGRAM_SEQUENCE, false, null, null);
            $this->progress->reconcile((int) $up['id']);
        });
        return $this->next->forUser($ctx->userId);
    }

    /** @return array<string,mixed> next action */
    public function advance(AuthContext $ctx, string $blockPublicId): array
    {
        $latest = $this->resolveRun($ctx, $blockPublicId);
        $this->db->transaction(function () use ($latest, $blockPublicId): void {
            $up = $this->ups->lock((int) $latest['id']);
            $block = $this->findBlock((int) $up['id'], $blockPublicId);
            if ($up['status'] === 'completed' || $block['block_status'] !== 'decision_required') {
                throw $this->notReady();
            }
            $nextBlock = null;
            foreach ($this->ups->blocks((int) $up['id']) as $b) {
                if ((int) $b['block_sequence'] > (int) $block['block_sequence']) {
                    $nextBlock = $b;
                    break;
                }
            }
            if ($nextBlock === null || $nextBlock['block_status'] !== 'not_started') {
                throw $this->notReady();   // BR-120: needs a following block; the last block can only extend or complete
            }
            $now = $this->clock->now();
            $this->ups->setBlockStatus((int) $block['ubp_id'], 'completed', null, $now);          // BR-121
            $this->ups->setBlockStatus((int) $nextBlock['ubp_id'], 'active', $now);
            $this->ups->setContinuation((int) $up['id'], RecommendationEngine::PROGRAM_SEQUENCE, false, null, null);   // BR-123
            $this->progress->reconcile((int) $up['id']);
        });
        return $this->next->forUser($ctx->userId);
    }

    /** @return array<string,mixed> the user's run, 404 BLOCK_NOT_FOUND when the block is not part of it (interpretation 6) */
    private function resolveRun(AuthContext $ctx, string $blockPublicId): array
    {
        $latest = Ulid::isValid($blockPublicId) ? $this->ups->latestForUser($ctx->userId) : null;
        if ($latest === null) {
            throw ApiException::notFound('BLOCK_NOT_FOUND', 'Trainingsblok niet gevonden.');
        }
        $this->findBlock((int) $latest['id'], $blockPublicId);
        return $latest;
    }

    /** @return array<string,mixed> */
    private function findBlock(int $userProgramId, string $blockPublicId): array
    {
        foreach ($this->ups->blocks($userProgramId) as $b) {
            if ($b['block_public_id'] === $blockPublicId) {
                return $b;
            }
        }
        throw ApiException::notFound('BLOCK_NOT_FOUND', 'Trainingsblok niet gevonden.');
    }

    private function notReady(): ApiException
    {
        return ApiException::conflict('BLOCK_NOT_READY_FOR_DECISION', 'Het blok staat niet op een beslismoment.');
    }

    private function noDecision(): ApiException
    {
        return ApiException::conflict('NO_CONTINUATION_DECISION', 'Er staat geen continuation decision open.');
    }
}
