<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;

final class UserRepository
{
    public function __construct(private Db $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE email = ?', [$email]);
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function create(string $publicId, string $name, string $email, string $passwordHash, string $timezone): int
    {
        $this->db->exec(
            'INSERT INTO users (public_id, name, email, password, timezone) VALUES (?, ?, ?, ?, ?)',
            [$publicId, $name, $email, $passwordHash, $timezone]
        );
        return $this->db->lastId();
    }

    public function updatePassword(int $id, string $hash): void
    {
        $this->db->exec('UPDATE users SET password = ? WHERE id = ?', [$hash, $id]);
    }

    /** @param array<string,string> $fields name/timezone */
    public function updateProfile(int $id, array $fields): void
    {
        $sets = [];
        $params = [];
        foreach (['name', 'timezone'] as $col) {
            if (array_key_exists($col, $fields)) {
                $sets[] = "$col = ?";
                $params[] = $fields[$col];
            }
        }
        if ($sets === []) {
            return;
        }
        $params[] = $id;
        $this->db->exec('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    public function count(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM users');
    }
}
