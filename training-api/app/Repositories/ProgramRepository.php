<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;

/** Read access to the shared (non user specific) training content. */
final class ProgramRepository
{
    public function __construct(private Db $db)
    {
    }

    /** @return array<string,mixed>|null the program new runs are started on */
    public function current(): ?array
    {
        return $this->db->one("SELECT * FROM programs WHERE status = 'active' ORDER BY id DESC LIMIT 1");
    }

    /** @return list<array<string,mixed>> */
    public function blocks(int $programId): array
    {
        return $this->db->all('SELECT * FROM training_blocks WHERE program_id = ? ORDER BY sequence', [$programId]);
    }

    /** @return list<array<string,mixed>> */
    public function blockWorkouts(int $trainingBlockId): array
    {
        return $this->db->all('SELECT * FROM block_workouts WHERE training_block_id = ? ORDER BY sequence', [$trainingBlockId]);
    }

    public function originalWeeks(int $programId): int
    {
        return (int) $this->db->value('SELECT COALESCE(MAX(original_week_end), 0) FROM training_blocks WHERE program_id = ?', [$programId]);
    }

    /** @return array<string,mixed>|null */
    public function activeTemplateByPublicId(string $publicId): ?array
    {
        return $this->db->one('SELECT * FROM workout_templates WHERE public_id = ? AND is_active = 1', [$publicId]);
    }

    /** @return list<array<string,mixed>> */
    public function exercises(int $templateId): array
    {
        return $this->db->all('SELECT * FROM workout_exercises WHERE workout_template_id = ? ORDER BY sequence', [$templateId]);
    }

    /** @return array<string,mixed>|null */
    public function template(int $templateId): ?array
    {
        return $this->db->one('SELECT * FROM workout_templates WHERE id = ?', [$templateId]);
    }
}
