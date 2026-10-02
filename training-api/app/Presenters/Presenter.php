<?php
declare(strict_types=1);

namespace App\Presenters;

use App\Support\Clock;

/** Shapes repository rows into the OpenAPI schemas. Internal BIGINT ids are never exposed (BR-183). */
final class Presenter
{
    /** @param array<string,mixed> $u */
    public static function user(array $u): array
    {
        return ['id' => $u['public_id'], 'name' => $u['name'], 'email' => $u['email'], 'timezone' => $u['timezone']];
    }

    /** @param array<string,mixed> $r */
    public static function workoutSummary(array $r): array
    {
        return [
            'id' => $r['template_public_id'],
            'name' => $r['workout_name'],
            'category' => $r['category'],
            'category_label' => $r['category_label'],
            'protocol_type' => $r['protocol_type'],
            'video_url' => $r['video_url'],
        ];
    }

    /**
     * @param array<string,mixed> $template workout_templates row
     * @param list<array<string,mixed>> $exercises
     */
    public static function workoutDetail(array $template, array $exercises): array
    {
        $config = json_decode((string) $template['protocol_config'], false);
        return [
            'id' => $template['public_id'],
            'name' => $template['name'],
            'category' => $template['category'],
            'category_label' => $template['category_label'],
            'protocol_type' => $template['protocol_type'],
            'video_url' => $template['video_url'],
            'instructions' => $template['instructions'],
            'protocol_config' => is_object($config) ? $config : new \stdClass(),
            'exercises' => array_map(static fn (array $e): array => [
                'id' => $e['public_id'],
                'sequence' => (int) $e['sequence'],
                'name' => $e['name'],
                'sets' => $e['sets'] === null ? null : (int) $e['sets'],
                'reps' => $e['reps'] === null ? null : (int) $e['reps'],
                'duration_seconds' => $e['duration_seconds'] === null ? null : (int) $e['duration_seconds'],
                'notes' => $e['notes'],
            ], $exercises),
        ];
    }

    /** @param array<string,mixed> $r row with block columns */
    public static function block(array $r): array
    {
        return [
            'id' => $r['block_public_id'],
            'name' => $r['block_name'],
            'sequence' => (int) $r['block_sequence'],
            'original_week_start' => (int) $r['original_week_start'],
            'original_week_end' => (int) $r['original_week_end'],
            'default_cycle_count' => (int) $r['default_cycle_count'],
            'target_cycles' => (int) $r['target_cycles'],
            'status' => $r['block_status'],
        ];
    }

    /** @param array<string,mixed> $r */
    public static function assignment(array $r): array
    {
        $extra = (int) $r['is_extra_cycle'] === 1;
        return [
            'id' => $r['public_id'],
            'cycle' => (int) $r['cycle_number'],
            'position' => (int) $r['sequence'],
            'day_label' => $r['day_label'],
            'program_week' => $extra ? null : (int) $r['original_week_start'] + (int) $r['cycle_number'] - 1,
            'status' => $r['status'],
            'is_extra_cycle' => $extra,
            'block' => self::block($r),
            'workout' => self::workoutSummary($r),
        ];
    }

    /**
     * @param array<string,mixed> $r assignment row
     * @param array<string,mixed> $template
     * @param list<array<string,mixed>> $exercises
     */
    public static function assignmentDetail(array $r, array $template, array $exercises): array
    {
        return self::assignment($r) + [
            'started_at' => Clock::toIso($r['started_at']),
            'completed_at' => Clock::toIso($r['completed_at']),
            'skipped_at' => Clock::toIso($r['skipped_at']),
            'workout_detail' => self::workoutDetail($template, $exercises),
        ];
    }

    /** @param array<string,mixed> $s */
    public static function session(array $s): array
    {
        return [
            'id' => $s['public_id'],
            'session_type' => $s['session_type'],
            'status' => $s['status'],
            'workout' => self::workoutSummary($s),
            'assignment_id' => $s['assignment_public_id'] ?? null,
            'started_at' => Clock::toIso($s['started_at']),
            'completed_at' => Clock::toIso($s['completed_at']),
            'notes' => $s['notes'],
        ];
    }
}
