<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Support\Clock;
use App\Support\Db;
use App\Support\Ulid;
use DateTimeImmutable;

/** Per-user program state: user_programs, user_program_blocks and workout_assignments. */
final class UserProgramRepository
{
    private const BLOCK_COLUMNS = 'ubp.id AS ubp_id, ubp.public_id AS ubp_public_id, ubp.user_program_id, ubp.status AS block_status,
        ubp.target_cycles, ubp.started_at AS block_started_at, ubp.completed_at AS block_completed_at,
        tb.id AS tb_id, tb.public_id AS block_public_id, tb.name AS block_name, tb.sequence AS block_sequence,
        tb.original_week_start, tb.original_week_end, tb.default_cycle_count';

    private const ASSIGNMENT_SELECT = 'SELECT a.id, a.public_id, a.cycle_number, a.sequence, a.status, a.is_extra_cycle,
        a.started_at, a.completed_at, a.skipped_at, a.user_program_block_id,
        bw.day_label, wt.id AS template_id, wt.public_id AS template_public_id, wt.name AS workout_name,
        wt.category, wt.category_label, wt.protocol_type, wt.video_url,
        up.user_id, up.public_id AS up_public_id, ' . self::BLOCK_COLUMNS . '
        FROM workout_assignments a
        JOIN user_program_blocks ubp ON ubp.id = a.user_program_block_id
        JOIN training_blocks tb ON tb.id = ubp.training_block_id
        JOIN block_workouts bw ON bw.id = a.block_workout_id
        JOIN workout_templates wt ON wt.id = bw.workout_template_id
        JOIN user_programs up ON up.id = ubp.user_program_id';

    public function __construct(private Db $db)
    {
    }

    /** @return array<string,mixed>|null the most recent run of the user (active, paused or completed) */
    public function latestForUser(int $userId): ?array
    {
        return $this->db->one(
            'SELECT up.*, p.public_id AS program_public_id, p.name AS program_name, p.version AS program_version
               FROM user_programs up JOIN programs p ON p.id = up.program_id
              WHERE up.user_id = ? ORDER BY up.id DESC LIMIT 1',
            [$userId]
        );
    }

    /** @return array<string,mixed> locks the row; callers must be inside a transaction */
    public function lock(int $id): array
    {
        $row = $this->db->one(
            'SELECT up.*, p.public_id AS program_public_id, p.name AS program_name, p.version AS program_version
               FROM user_programs up JOIN programs p ON p.id = up.program_id WHERE up.id = ? FOR UPDATE',
            [$id]
        );
        if ($row === null) {
            throw new \LogicException('user_program disappeared: ' . $id);
        }
        return $row;
    }

    public function hasOpenRun(int $userId, int $programId): bool
    {
        return $this->db->value(
            "SELECT 1 FROM user_programs WHERE user_id = ? AND program_id = ? AND status IN ('active','paused') LIMIT 1",
            [$userId, $programId]
        ) !== null;
    }

    /** @param array<string,mixed> $startPosition */
    public function createRun(int $userId, int $programId, string $startMode, ?int $week, ?int $day, DateTimeImmutable $now): array
    {
        $publicId = Ulid::generate();
        $this->db->exec(
            "INSERT INTO user_programs (public_id, user_id, program_id, status, start_mode, start_original_week, start_day_sequence, started_at)
             VALUES (?, ?, ?, 'active', ?, ?, ?, ?)",
            [$publicId, $userId, $programId, $startMode, $week, $day, Clock::toDb($now)]
        );
        return ['id' => $this->db->lastId(), 'public_id' => $publicId];
    }

    public function createBlock(int $userProgramId, int $trainingBlockId, int $targetCycles, string $status, ?DateTimeImmutable $startedAt): int
    {
        $this->db->exec(
            'INSERT INTO user_program_blocks (public_id, user_program_id, training_block_id, target_cycles, status, started_at) VALUES (?, ?, ?, ?, ?, ?)',
            [Ulid::generate(), $userProgramId, $trainingBlockId, $targetCycles, $status, $startedAt ? Clock::toDb($startedAt) : null]
        );
        return $this->db->lastId();
    }

    /** @param list<array{ubp:int,bw:int,cycle:int,seq:int,status:string,extra:int}> $rows */
    public function insertAssignments(array $rows): void
    {
        foreach (array_chunk($rows, 50) as $chunk) {
            $sql = 'INSERT INTO workout_assignments (public_id, user_program_block_id, block_workout_id, cycle_number, sequence, status, is_extra_cycle) VALUES '
                . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?)'));
            $params = [];
            foreach ($chunk as $r) {
                array_push($params, Ulid::generate(), $r['ubp'], $r['bw'], $r['cycle'], $r['seq'], $r['status'], $r['extra']);
            }
            $this->db->exec($sql, $params);
        }
    }

    /** @return list<array<string,mixed>> */
    public function blocks(int $userProgramId): array
    {
        return $this->db->all(
            'SELECT ' . self::BLOCK_COLUMNS . ' FROM user_program_blocks ubp JOIN training_blocks tb ON tb.id = ubp.training_block_id
              WHERE ubp.user_program_id = ? ORDER BY tb.sequence',
            [$userProgramId]
        );
    }

    public function setBlockStatus(int $ubpId, string $status, ?DateTimeImmutable $startedAt = null, ?DateTimeImmutable $completedAt = null): void
    {
        $sets = ['status = ?'];
        $params = [$status];
        if ($startedAt !== null) {
            $sets[] = 'started_at = ?';
            $params[] = Clock::toDb($startedAt);
        }
        if ($completedAt !== null) {
            $sets[] = 'completed_at = ?';
            $params[] = Clock::toDb($completedAt);
        }
        $params[] = $ubpId;
        $this->db->exec('UPDATE user_program_blocks SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    public function setTargetCycles(int $ubpId, int $cycles): void
    {
        $this->db->exec('UPDATE user_program_blocks SET target_cycles = ? WHERE id = ?', [$cycles, $ubpId]);
    }

    /** @return array<string,mixed>|null */
    public function assignmentByPublicId(string $publicId): ?array
    {
        return $this->db->one(self::ASSIGNMENT_SELECT . ' WHERE a.public_id = ?', [$publicId]);
    }

    /** @return list<array<string,mixed>> in recommendation order (cycle, position) */
    public function assignmentsForBlock(int $ubpId): array
    {
        return $this->db->all(self::ASSIGNMENT_SELECT . ' WHERE a.user_program_block_id = ? ORDER BY a.cycle_number, a.sequence', [$ubpId]);
    }

    /** @return list<array<string,mixed>> */
    public function assignmentsForProgram(int $userProgramId): array
    {
        return $this->db->all(self::ASSIGNMENT_SELECT . ' WHERE ubp.user_program_id = ? ORDER BY tb.sequence, a.cycle_number, a.sequence', [$userProgramId]);
    }

    /** @return array<string,mixed>|null */
    public function startedAssignment(int $userProgramId): ?array
    {
        return $this->db->one(self::ASSIGNMENT_SELECT . " WHERE ubp.user_program_id = ? AND a.status = 'started' LIMIT 1", [$userProgramId]);
    }

    public function setAssignmentState(int $id, string $status, ?DateTimeImmutable $startedAt, ?DateTimeImmutable $completedAt, ?DateTimeImmutable $skippedAt): void
    {
        $this->db->exec(
            'UPDATE workout_assignments SET status = ?, started_at = ?, completed_at = ?, skipped_at = ? WHERE id = ?',
            [
                $status,
                $startedAt ? Clock::toDb($startedAt) : null,
                $completedAt ? Clock::toDb($completedAt) : null,
                $skippedAt ? Clock::toDb($skippedAt) : null,
                $id,
            ]
        );
    }

    public function setContinuation(int $userProgramId, string $mode, bool $decisionRequired, ?string $anchorPublicId, ?string $sourcePublicId): void
    {
        $this->db->exec(
            'UPDATE user_programs SET continuation_mode = ?, continuation_decision_required = ?,
                    continuation_anchor_assignment_public_id = ?, continuation_source_assignment_public_id = ? WHERE id = ?',
            [$mode, $decisionRequired ? 1 : 0, $anchorPublicId, $sourcePublicId, $userProgramId]
        );
    }

    public function complete(int $userProgramId, DateTimeImmutable $at): void
    {
        $this->db->exec("UPDATE user_programs SET status = 'completed', completed_at = ? WHERE id = ?", [Clock::toDb($at), $userProgramId]);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one(
            'SELECT up.*, p.public_id AS program_public_id, p.name AS program_name, p.version AS program_version
               FROM user_programs up JOIN programs p ON p.id = up.program_id WHERE up.id = ?',
            [$id]
        );
    }
}
