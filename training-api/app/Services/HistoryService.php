<?php
declare(strict_types=1);

namespace App\Services;

use App\Presenters\Presenter;
use App\Support\Clock;
use App\Support\Db;

/**
 * BR-140..BR-145: completed sessions (program + extra) and explicit skips across all runs of the user, newest first.
 * prior_to_start and cancelled sessions never appear.
 */
final class HistoryService
{
    public function __construct(private Db $db)
    {
    }

    /** @return array{data:list<array<string,mixed>>,meta:array<string,int>} */
    public function page(int $userId, int $page, int $perPage): array
    {
        return $this->db->transaction(fn (): array => $this->build($userId, $page, $perPage));
    }

    /** @return array{data:list<array<string,mixed>>,meta:array<string,int>} */
    private function build(int $userId, int $page, int $perPage): array
    {
        $total = (int) $this->db->value(
            "SELECT (SELECT COUNT(*) FROM workout_sessions WHERE user_id = ? AND status = 'completed')
                  + (SELECT COUNT(*) FROM workout_assignments a
                       JOIN user_program_blocks ubp ON ubp.id = a.user_program_block_id
                       JOIN user_programs up ON up.id = ubp.user_program_id
                      WHERE up.user_id = ? AND a.status = 'skipped' AND a.skipped_at IS NOT NULL)",
            [$userId, $userId]
        );
        $refs = $this->db->all(
            "SELECT kind, ref_id, occurred_at FROM (
                SELECT 'session' AS kind, ws.id AS ref_id, ws.completed_at AS occurred_at
                  FROM workout_sessions ws WHERE ws.user_id = ? AND ws.status = 'completed'
                UNION ALL
                SELECT 'skip' AS kind, a.id AS ref_id, a.skipped_at AS occurred_at
                  FROM workout_assignments a
                  JOIN user_program_blocks ubp ON ubp.id = a.user_program_block_id
                  JOIN user_programs up ON up.id = ubp.user_program_id
                 WHERE up.user_id = ? AND a.status = 'skipped' AND a.skipped_at IS NOT NULL
             ) h ORDER BY occurred_at DESC, kind DESC, ref_id DESC LIMIT ? OFFSET ?",
            [$userId, $userId, $perPage, ($page - 1) * $perPage]
        );

        $sessionIds = [];
        $skipIds = [];
        foreach ($refs as $r) {
            if ($r['kind'] === 'session') {
                $sessionIds[] = (int) $r['ref_id'];
            } else {
                $skipIds[] = (int) $r['ref_id'];
            }
        }
        $sessions = $this->sessionRows($sessionIds);
        $skips = $this->skipRows($skipIds);

        $data = [];
        foreach ($refs as $r) {
            $row = $r['kind'] === 'session' ? $sessions[(int) $r['ref_id']] : $skips[(int) $r['ref_id']];
            $data[] = $r['kind'] === 'session' ? $this->sessionItem($row) : $this->skipItem($row);
        }
        return [
            'data' => $data,
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))],
        ];
    }

    /**
     * @param list<int> $ids
     * @return array<int,array<string,mixed>>
     */
    private function sessionRows(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->all(
            'SELECT ws.id, ws.public_id, ws.session_type, ws.completed_at, ws.notes, wa.public_id AS assignment_public_id, wa.cycle_number,
                    wt.public_id AS template_public_id, wt.name AS workout_name, wt.category, wt.category_label, wt.protocol_type, wt.video_url,
                    tb.public_id AS block_public_id, tb.name AS block_name, tb.sequence AS block_sequence, tb.original_week_start,
                    tb.original_week_end, tb.default_cycle_count, ubp.target_cycles, ubp.status AS block_status
               FROM workout_sessions ws
               JOIN workout_templates wt ON wt.id = ws.workout_template_id
               LEFT JOIN workout_assignments wa ON wa.id = ws.workout_assignment_id
               LEFT JOIN user_program_blocks ubp ON ubp.id = wa.user_program_block_id
               LEFT JOIN training_blocks tb ON tb.id = ubp.training_block_id
              WHERE ws.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids
        );
        return array_column($rows, null, 'id');
    }

    /**
     * @param list<int> $ids
     * @return array<int,array<string,mixed>>
     */
    private function skipRows(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->all(
            'SELECT a.id, a.public_id AS assignment_public_id, a.cycle_number, a.skipped_at,
                    wt.public_id AS template_public_id, wt.name AS workout_name, wt.category, wt.category_label, wt.protocol_type, wt.video_url,
                    tb.public_id AS block_public_id, tb.name AS block_name, tb.sequence AS block_sequence, tb.original_week_start,
                    tb.original_week_end, tb.default_cycle_count, ubp.target_cycles, ubp.status AS block_status
               FROM workout_assignments a
               JOIN user_program_blocks ubp ON ubp.id = a.user_program_block_id
               JOIN training_blocks tb ON tb.id = ubp.training_block_id
               JOIN block_workouts bw ON bw.id = a.block_workout_id
               JOIN workout_templates wt ON wt.id = bw.workout_template_id
              WHERE a.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids
        );
        return array_column($rows, null, 'id');
    }

    /** @param array<string,mixed> $r */
    private function sessionItem(array $r): array
    {
        $isExtra = $r['session_type'] === 'extra';
        return [
            'event_type' => $isExtra ? 'extra_completed' : 'completed',
            'occurred_at' => Clock::toIso($r['completed_at']),
            'workout' => Presenter::workoutSummary($r),
            'block' => $isExtra || $r['block_public_id'] === null ? null : Presenter::block($r),
            'cycle' => $isExtra || $r['cycle_number'] === null ? null : (int) $r['cycle_number'],
            'assignment_id' => $r['assignment_public_id'],
            'session_id' => $r['public_id'],
            'notes' => $r['notes'],
        ];
    }

    /** @param array<string,mixed> $r */
    private function skipItem(array $r): array
    {
        return [
            'event_type' => 'skipped',
            'occurred_at' => Clock::toIso($r['skipped_at']),
            'workout' => Presenter::workoutSummary($r),
            'block' => Presenter::block($r),
            'cycle' => (int) $r['cycle_number'],
            'assignment_id' => $r['assignment_public_id'],
            'session_id' => null,
            'notes' => null,
        ];
    }
}
