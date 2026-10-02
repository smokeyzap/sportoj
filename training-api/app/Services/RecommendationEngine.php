<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Pure recommendation rules (BR-030..BR-063). No database, no clock: calendar days never matter (BR-025, BR-043).
 *
 * Input is the ordered assignment list (cycle, position) of the ACTIVE block only.
 */
final class RecommendationEngine
{
    public const PROGRAM_SEQUENCE = 'program_sequence';
    public const LAST_WORKOUT_SEQUENCE = 'last_workout_sequence';

    /** @param array<string,mixed> $a */
    public static function isTerminal(array $a): bool
    {
        return in_array($a['status'], ['completed', 'skipped', 'prior_to_start'], true);
    }

    /**
     * @param list<array<string,mixed>> $assignments ordered by cycle, position
     * @return array{assignment:?array<string,mixed>,mode:string,fell_back:bool}
     */
    public static function recommend(array $assignments, string $mode, ?string $anchorPublicId): array
    {
        $pending = array_values(array_filter($assignments, static fn (array $a): bool => $a['status'] === 'pending'));
        if ($pending === []) {
            return ['assignment' => null, 'mode' => $mode, 'fell_back' => false];
        }
        if ($mode === self::LAST_WORKOUT_SEQUENCE) {
            $anchorIndex = null;
            foreach ($assignments as $i => $a) {
                if ($a['public_id'] === $anchorPublicId) {
                    $anchorIndex = $i;
                    break;
                }
            }
            if ($anchorIndex !== null) {
                foreach ($assignments as $i => $a) {
                    if ($i > $anchorIndex && $a['status'] === 'pending') {
                        return ['assignment' => $a, 'mode' => $mode, 'fell_back' => false];
                    }
                }
            }
            // BR-062: nothing pending after the anchor (or no usable anchor) -> back to program order.
            return ['assignment' => $pending[0], 'mode' => self::PROGRAM_SEQUENCE, 'fell_back' => true];
        }
        return ['assignment' => $pending[0], 'mode' => self::PROGRAM_SEQUENCE, 'fell_back' => false];
    }

    /**
     * @param list<array<string,mixed>> $assignments
     * @return array{processed:int,total:int,completed:int,skipped:int,pending:int}
     */
    public static function counts(array $assignments): array
    {
        $c = ['processed' => 0, 'total' => count($assignments), 'completed' => 0, 'skipped' => 0, 'pending' => 0];
        foreach ($assignments as $a) {
            if (self::isTerminal($a)) {
                $c['processed']++;
            } else {
                $c['pending']++;
            }
            if ($a['status'] === 'completed') {
                $c['completed']++;
            } elseif ($a['status'] === 'skipped') {
                $c['skipped']++;
            }
        }
        return $c;
    }

    /**
     * A cycle is processed when all of its assignments are terminal (BR-100).
     *
     * @param list<array<string,mixed>> $assignments
     */
    public static function cyclesProcessed(array $assignments): int
    {
        $byCycle = [];
        foreach ($assignments as $a) {
            $byCycle[(int) $a['cycle_number']][] = self::isTerminal($a);
        }
        $n = 0;
        foreach ($byCycle as $flags) {
            if (!in_array(false, $flags, true)) {
                $n++;
            }
        }
        return $n;
    }
}
