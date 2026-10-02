<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Support\Clock;
use App\Support\Db;
use App\Support\Ulid;
use DateTimeImmutable;

/** workout_sessions. Rows carry the template summary under the same aliases the presenters expect. */
final class SessionRepository
{
    private const SELECT = 'SELECT ws.id, ws.public_id, ws.user_id, ws.user_program_id, ws.workout_assignment_id, ws.session_type, ws.status,
        ws.started_as_recommended, ws.started_at, ws.completed_at, ws.cancelled_at, ws.notes,
        wt.id AS template_id, wt.public_id AS template_public_id, wt.name AS workout_name, wt.category, wt.category_label,
        wt.protocol_type, wt.video_url, wa.public_id AS assignment_public_id
        FROM workout_sessions ws
        JOIN workout_templates wt ON wt.id = ws.workout_template_id
        LEFT JOIN workout_assignments wa ON wa.id = ws.workout_assignment_id';

    public function __construct(private Db $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function byPublicId(string $publicId): ?array
    {
        return $this->db->one(self::SELECT . ' WHERE ws.public_id = ?', [$publicId]);
    }

    /** @return array<string,mixed>|null */
    public function byId(int $id): ?array
    {
        return $this->db->one(self::SELECT . ' WHERE ws.id = ?', [$id]);
    }

    /** @return array<string,mixed>|null */
    public function lockByPublicId(string $publicId): ?array
    {
        $id = $this->db->value('SELECT id FROM workout_sessions WHERE public_id = ? FOR UPDATE', [$publicId]);
        return $id === null ? null : $this->byId((int) $id);
    }

    /** @return array<string,mixed>|null */
    public function startedForAssignment(int $assignmentId): ?array
    {
        return $this->db->one(self::SELECT . " WHERE ws.workout_assignment_id = ? AND ws.status = 'started' ORDER BY ws.id DESC LIMIT 1", [$assignmentId]);
    }

    /** @return array<string,mixed>|null */
    public function latestCompletedForAssignment(int $assignmentId): ?array
    {
        return $this->db->one(self::SELECT . " WHERE ws.workout_assignment_id = ? AND ws.status = 'completed' ORDER BY ws.id DESC LIMIT 1", [$assignmentId]);
    }

    /** @return array<string,mixed>|null */
    public function startedExtra(int $userId, int $templateId): ?array
    {
        return $this->db->one(
            self::SELECT . " WHERE ws.user_id = ? AND ws.workout_template_id = ? AND ws.session_type = 'extra' AND ws.status = 'started' ORDER BY ws.id DESC LIMIT 1",
            [$userId, $templateId]
        );
    }

    public function createProgramSession(int $userId, int $userProgramId, int $assignmentId, int $templateId, bool $startedAsRecommended, DateTimeImmutable $now): int
    {
        $this->db->exec(
            "INSERT INTO workout_sessions (public_id, user_id, user_program_id, workout_assignment_id, workout_template_id, session_type, status, started_as_recommended, started_at)
             VALUES (?, ?, ?, ?, ?, 'program', 'started', ?, ?)",
            [Ulid::generate(), $userId, $userProgramId, $assignmentId, $templateId, $startedAsRecommended ? 1 : 0, Clock::toDb($now)]
        );
        return $this->db->lastId();
    }

    public function createExtraSession(int $userId, ?int $userProgramId, int $templateId, DateTimeImmutable $now): int
    {
        $this->db->exec(
            "INSERT INTO workout_sessions (public_id, user_id, user_program_id, workout_assignment_id, workout_template_id, session_type, status, started_as_recommended, started_at)
             VALUES (?, ?, ?, NULL, ?, 'extra', 'started', NULL, ?)",
            [Ulid::generate(), $userId, $userProgramId, $templateId, Clock::toDb($now)]
        );
        return $this->db->lastId();
    }

    public function complete(int $id, ?string $notes, DateTimeImmutable $now): void
    {
        $this->db->exec("UPDATE workout_sessions SET status = 'completed', completed_at = ?, notes = ? WHERE id = ?", [Clock::toDb($now), $notes, $id]);
    }

    /** Cancels started sessions of an assignment (skip). */
    public function cancelStartedForAssignment(int $assignmentId, DateTimeImmutable $now): void
    {
        $this->db->exec(
            "UPDATE workout_sessions SET status = 'cancelled', cancelled_at = ? WHERE workout_assignment_id = ? AND status = 'started'",
            [Clock::toDb($now), $assignmentId]
        );
    }

    /** Marks the completion as corrected (reopen). No hard delete (BR-087). */
    public function cancelCompletedForAssignment(int $assignmentId, DateTimeImmutable $now): void
    {
        $this->db->exec(
            "UPDATE workout_sessions SET status = 'cancelled', cancelled_at = ? WHERE workout_assignment_id = ? AND status = 'completed'",
            [Clock::toDb($now), $assignmentId]
        );
    }
}
