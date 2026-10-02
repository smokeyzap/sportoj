<?php
declare(strict_types=1);

namespace App\Services;

use App\Presenters\Presenter;
use App\Repositories\ProgramRepository;
use App\Repositories\UserProgramRepository;
use App\Support\Clock;
use App\Support\Db;
use LogicException;

/** Builds the NextAction payload of GET /me/today and of every mutation (BR-030..BR-032, BR-075). */
final class NextActionService
{
    public function __construct(private Db $db, private UserProgramRepository $ups, private ProgramRepository $programs)
    {
    }

    /** @return array<string,mixed> */
    public function forUser(int $userId): array
    {
        // One snapshot for all the reads below (a no-op inside a mutation's own transaction).
        return $this->db->transaction(fn (): array => $this->build($userId));
    }

    /** @return array<string,mixed> */
    private function build(int $userId): array
    {
        $up = $this->ups->latestForUser($userId);
        if ($up === null) {
            return $this->onboarding();
        }
        if ($up['status'] === 'completed') {
            return [
                'type' => 'program_completed',
                'user_program_id' => $up['public_id'],
                'completed_at' => Clock::toIso($up['completed_at']),
            ];
        }
        return $this->forRun($up);
    }

    /** @return array<string,mixed> */
    public function onboarding(): array
    {
        $program = $this->programs->current();
        if ($program === null) {
            throw new LogicException('No active program content installed.');
        }
        return [
            'type' => 'onboarding',
            'program' => [
                'id' => $program['public_id'],
                'name' => $program['name'],
                'version' => $program['version'],
                'original_weeks' => $this->programs->originalWeeks((int) $program['id']),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $up an active/paused run row
     * @return array<string,mixed>
     */
    public function forRun(array $up): array
    {
        $blocks = $this->ups->blocks((int) $up['id']);
        $active = null;
        foreach ($blocks as $b) {
            if (in_array($b['block_status'], ['active', 'decision_required'], true)) {
                $active = $b;
                break;
            }
        }
        if ($active === null) {
            throw new LogicException('Run ' . $up['public_id'] . ' has no active block.');
        }
        $assignments = $this->ups->assignmentsForBlock((int) $active['ubp_id']);

        // BR-032 priority 2: open continuation decision.
        if ((int) $up['continuation_decision_required'] === 1) {
            $source = $this->ups->assignmentByPublicId((string) $up['continuation_source_assignment_public_id']);
            if ($source !== null) {
                return [
                    'type' => 'continuation_decision',
                    'source_assignment' => Presenter::assignment($source),
                    'options' => [RecommendationEngine::PROGRAM_SEQUENCE, RecommendationEngine::LAST_WORKOUT_SEQUENCE],
                ];
            }
        }

        // Priority 3: block decision.
        if ($active['block_status'] === 'decision_required') {
            $isLast = (int) $active['block_sequence'] === (int) max(array_map(static fn (array $b): int => (int) $b['block_sequence'], $blocks));
            $counts = RecommendationEngine::counts($assignments);
            return [
                'type' => 'block_decision',
                'block' => Presenter::block($active),
                'summary' => [
                    'completed' => $counts['completed'],
                    'skipped' => $counts['skipped'],
                    'cycles_processed' => RecommendationEngine::cyclesProcessed($assignments),
                ],
                'options' => $isLast ? ['extend', 'complete_program'] : ['extend', 'advance'],
            ];
        }

        // Priority 4: resume the started program workout.
        foreach ($assignments as $a) {
            if ($a['status'] === 'started') {
                return $this->workoutAction('resume_workout', 'Je hebt een training die nog bezig is.', $a, $active, $blocks, $assignments);
            }
        }

        // Priority 5: next workout according to the active continuation mode.
        $rec = RecommendationEngine::recommend($assignments, (string) $up['continuation_mode'], $up['continuation_anchor_assignment_public_id']);
        if ($rec['assignment'] === null) {
            throw new LogicException('Active block has neither pending work nor decision state.');
        }
        $reason = $rec['mode'] === RecommendationEngine::LAST_WORKOUT_SEQUENCE
            ? 'Eerstvolgende training na je laatst voltooide workout.'
            : 'Eerstvolgende openstaande training in je programmavolgorde.';
        return $this->workoutAction('workout', $reason, $rec['assignment'], $active, $blocks, $assignments);
    }

    /**
     * @param array<string,mixed> $assignment
     * @param array<string,mixed> $active
     * @param list<array<string,mixed>> $blocks
     * @param list<array<string,mixed>> $assignments
     * @return array<string,mixed>
     */
    private function workoutAction(string $type, string $reason, array $assignment, array $active, array $blocks, array $assignments): array
    {
        $c = RecommendationEngine::counts($assignments);
        return [
            'type' => $type,
            'reason' => $reason,
            'assignment' => Presenter::assignment($assignment),
            'progress' => [
                'block_number' => (int) $active['block_sequence'],
                'block_count' => count($blocks),
                'cycle' => (int) $assignment['cycle_number'],
                'target_cycles' => (int) $active['target_cycles'],
                'processed' => $c['processed'],
                'total' => $c['total'],
                'completed' => $c['completed'],
                'skipped' => $c['skipped'],
                'pending' => $c['pending'],
            ],
        ];
    }
}
