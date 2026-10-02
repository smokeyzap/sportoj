<?php
declare(strict_types=1);

namespace Tests\Support;

final class Env
{
    /** @return array<string,string> */
    public static function dbSettings(?string $database = null): array
    {
        $g = static fn (string $k, string $d): string => (string) (getenv($k) !== false ? getenv($k) : ($_ENV[$k] ?? $d));
        return [
            'DB_HOST' => $g('TEST_DB_HOST', '127.0.0.1'),
            'DB_PORT' => $g('TEST_DB_PORT', '3306'),
            'DB_USERNAME' => $g('TEST_DB_USERNAME', 'training'),
            'DB_PASSWORD' => $g('TEST_DB_PASSWORD', 'training'),
            'DB_DATABASE' => $database ?? $g('TEST_DB_DATABASE', 'training_test'),
        ];
    }

    /** Drops and recreates an (empty) database. Only names containing "test" are accepted. */
    public static function recreateDatabase(string $name): void
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1 || !str_contains($name, 'test')) {
            throw new \RuntimeException("Refusing to touch database '$name'.");
        }
        $s = self::dbSettings();
        $pdo = new \PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $s['DB_HOST'], (int) $s['DB_PORT']),
            $s['DB_USERNAME'],
            $s['DB_PASSWORD'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec("DROP DATABASE IF EXISTS `$name`");
        $pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
}
