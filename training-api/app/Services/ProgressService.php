<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\UserProgramRepository;

/**
 * Derives and persists progress state after every mutation: block decision_required <-> active (BR-110, BR-63)
 * and the automatic continuation fallback (BR-062). Must be called inside the program-locked transaction.
 */
final class ProgressService
{
    public function __construct(private UserProgramRepository $ups)
    {
    }

    public function reconcile(int $userProgramId): void
    {
        $up = $this->ups->find($userProgramId);
        if ($up === null || $up['status'] === 'completed') {
            return;
        }
        $active = null;
        foreach ($this->ups->blocks($userProgramId) as $b) {
            if (in_array($b['block_status'], ['active', 'decision_required'], true)) {
                $active = $b;
                break;
            }
        }
        if ($active === null) {
            return;
        }
        $assignments = $this->ups->assignmentsForBlock((int) $active['ubp_id']);
        $open = count(array_filter($assignments, static fn (array $a): bool => !RecommendationEngine::isTerminal($a)));

        if ($open === 0) {
            if ($active['block_status'] === 'active') {
                $this->ups->setBlockStatus((int) $active['ubp_id'], 'decision_required');
            }
            // Nothing left to continue from: any continuation state is moot (BR-123 for the block boundary).
            if ($up['continuation_mode'] !== RecommendationEngine::PROGRAM_SEQUENCE || (int) $up['continuation_decision_required'] === 1
                || $up['continuation_anchor_assignment_public_id'] !== null || $up['continuation_source_assignment_public_id'] !== null) {
                $this->ups->setContinuation($userProgramId, RecommendationEngine::PROGRAM_SEQUENCE, false, null, null);
            }
            return;
        }

        if ($active['block_status'] === 'decision_required') {
            $this->ups->setBlockStatus((int) $active['ubp_id'], 'active');
        }
        if ($up['continuation_mode'] === RecommendationEngine::LAST_WORKOUT_SEQUENCE && (int) $up['continuation_decision_required'] === 0) {
            $rec = RecommendationEngine::recommend($assignments, $up['continuation_mode'], $up['continuation_anchor_assignment_public_id']);
            if ($rec['fell_back']) {
                $this->ups->setContinuation($userProgramId, RecommendationEngine::PROGRAM_SEQUENCE, false, null, null);
            }
        }
    }
}
