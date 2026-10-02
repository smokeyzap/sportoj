<?php
declare(strict_types=1);

namespace App\Services;

use App\Http\ApiException;
use App\Presenters\Presenter;
use App\Repositories\ProgramRepository;
use App\Repositories\UserProgramRepository;
use App\Support\Clock;
use App\Support\Db;

/** Starting/restarting a run, formal completion and the read model of GET /me/program. */
final class ProgramService
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

    /**
     * Start (or restart after completion) a run. Creates all standard assignments (BR-006) in one transaction.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed> next action
     */
    public function start(int $userId, array $input): array
    {
        $program = $this->programs->current();
        if ($program === null) {
            throw new \LogicException('No active program content installed.');
        }
        [$mode, $week, $day] = $this->validateStart($input, $this->programs->originalWeeks((int) $program['id']));
        $now = $this->clock->now();

        $this->db->transaction(function () use ($userId, $program, $mode, $week, $day, $now): void {
            // Serialise concurrent starts of the same user (BR-005).
            $this->db->run('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if ($this->ups->hasOpenRun($userId, (int) $program['id'])) {
                throw ApiException::conflict('ACTIVE_PROGRAM_EXISTS', 'Er bestaat al een actief programma.');
            }
            $blocks = $this->programs->blocks((int) $program['id']);
            [$startBlockSeq, $startCycle, $startPos] = $this->resolveStart($blocks, $mode, $week, $day);

            $run = $this->ups->createRun($userId, (int) $program['id'], $mode, $week, $day, $now);
            $assignments = [];
            foreach ($blocks as $block) {
                $seq = (int) $block['sequence'];
                $status = $seq < $startBlockSeq ? 'prior_to_start' : ($seq === $startBlockSeq ? 'active' : 'not_started');
                $ubpId = $this->ups->createBlock((int) $run['id'], (int) $block['id'], (int) $block['default_cycle_count'], $status, $status === 'active' ? $now : null);
                foreach ($this->programs->blockWorkouts((int) $block['id']) as $bw) {
                    for ($cycle = 1; $cycle <= (int) $block['default_cycle_count']; $cycle++) {
                        $pos = (int) $bw['sequence'];
                        $before = $seq < $startBlockSeq
                            || ($seq === $startBlockSeq && ($cycle < $startCycle || ($cycle === $startCycle && $pos < $startPos)));
                        $assignments[] = ['ubp' => $ubpId, 'bw' => (int) $bw['id'], 'cycle' => $cycle, 'seq' => $pos, 'status' => $before ? 'prior_to_start' : 'pending', 'extra' => 0];
                    }
                }
            }
            usort($assignments, static fn (array $a, array $b): int => [$a['ubp'], $a['cycle'], $a['seq']] <=> [$b['ubp'], $b['cycle'], $b['seq']]);
            $this->ups->insertAssignments($assignments);
            $this->progress->reconcile((int) $run['id']);
        });

        return $this->next->forUser($userId);
    }

    /**
     * @param array<string,mixed> $input
     * @return array{0:string,1:?int,2:?int}
     */
    private function validateStart(array $input, int $originalWeeks): array
    {
        $mode = $input['start_mode'] ?? null;
        if (!is_string($mode) || !in_array($mode, ['beginning', 'position'], true)) {
            throw ApiException::validation(['start_mode' => 'Moet "beginning" of "position" zijn.']);
        }
        if ($mode === 'beginning') {
            return [$mode, null, null];
        }
        $week = $input['original_week'] ?? null;
        $day = $input['day_sequence'] ?? null;
        $errors = [];
        if (!is_int($week)) {
            $errors['original_week'] = 'Verplicht (geheel getal) bij start_mode=position.';
        }
        if (!is_int($day)) {
            $errors['day_sequence'] = 'Verplicht (geheel getal) bij start_mode=position.';
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        if ($week < 1 || $week > $originalWeeks || $day < 1 || $day > 6) {
            throw new ApiException(422, 'INVALID_START_POSITION', 'Ongeldige startpositie.', [
                'fields' => ['original_week' => "1 t/m $originalWeeks", 'day_sequence' => '1 t/m 6'],
            ]);
        }
        return [$mode, $week, $day];
    }

    /**
     * BR-013: translate original week + position to block, cycle and position.
     *
     * @param list<array<string,mixed>> $blocks
     * @return array{0:int,1:int,2:int}
     */
    private function resolveStart(array $blocks, string $mode, ?int $week, ?int $day): array
    {
        if ($mode === 'beginning') {
            return [(int) $blocks[0]['sequence'], 1, 1];
        }
        foreach ($blocks as $b) {
            if ($week >= (int) $b['original_week_start'] && $week <= (int) $b['original_week_end']) {
                return [(int) $b['sequence'], $week - (int) $b['original_week_start'] + 1, $day];
            }
        }
        throw new ApiException(422, 'INVALID_START_POSITION', 'Ongeldige startpositie.');
    }

    /** POST /me/program/complete (BR-131). */
    public function complete(int $userId): array
    {
        $latest = $this->ups->latestForUser($userId);
        if ($latest === null) {
            throw ApiException::conflict('PROGRAM_NOT_READY_TO_COMPLETE', 'Het programma kan nog niet worden afgerond.');
        }
        $this->db->transaction(function () use ($latest): void {
            $up = $this->ups->lock((int) $latest['id']);
            if ($up['status'] === 'completed') {
                // Approved interpretation 1: a repeated decision is a 409 BLOCK_NOT_READY_FOR_DECISION.
                throw ApiException::conflict('BLOCK_NOT_READY_FOR_DECISION', 'Het blok staat niet op een beslismoment.');
            }
            $blocks = $this->ups->blocks((int) $up['id']);
            $last = end($blocks);
            if ($last === false || $last['block_status'] !== 'decision_required') {
                throw ApiException::conflict('PROGRAM_NOT_READY_TO_COMPLETE', 'Het programma kan nog niet worden afgerond.');
            }
            $now = $this->clock->now();
            $this->ups->setBlockStatus((int) $last['ubp_id'], 'completed', null, $now);
            $this->ups->complete((int) $up['id'], $now);
            $this->ups->setContinuation((int) $up['id'], RecommendationEngine::PROGRAM_SEQUENCE, false, null, null);
        });
        return $this->next->forUser($userId);
    }

    /** GET /me/program. @return array<string,mixed>|null */
    public function state(int $userId): ?array
    {
        $up = $this->ups->latestForUser($userId);
        if ($up === null) {
            return null;
        }
        $blocks = [];
        $index = [];
        foreach ($this->ups->blocks((int) $up['id']) as $b) {
            $index[(int) $b['ubp_id']] = count($blocks);
            $blocks[] = ['block' => Presenter::block($b), 'cycles' => []];
        }
        foreach ($this->ups->assignmentsForProgram((int) $up['id']) as $a) {
            $i = $index[(int) $a['user_program_block_id']];
            $cycle = (int) $a['cycle_number'];
            $last = count($blocks[$i]['cycles']) - 1;
            if ($last < 0 || $blocks[$i]['cycles'][$last]['number'] !== $cycle) {
                $blocks[$i]['cycles'][] = ['number' => $cycle, 'is_extra' => (int) $a['is_extra_cycle'] === 1, 'assignments' => []];
                $last++;
            }
            $blocks[$i]['cycles'][$last]['assignments'][] = Presenter::assignment($a);
        }
        return [
            'id' => $up['public_id'],
            'program' => ['id' => $up['program_public_id'], 'name' => $up['program_name'], 'version' => $up['program_version']],
            'status' => $up['status'],
            'continuation_mode' => $up['continuation_mode'],
            'blocks' => $blocks,
        ];
    }
}
