<?php
declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOException;
use PDOStatement;

/** Thin PDO wrapper: UTC session, real prepared statements, nested-safe transactions with deadlock retry. */
final class Db
{
    private ?PDO $pdo = null;
    private int $depth = 0;

    public function __construct(private Config $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $socket = $this->config->get('DB_SOCKET');
            $dsn = $socket !== null
                ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $socket, $this->config->get('DB_DATABASE', ''))
                : sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $this->config->get('DB_HOST', 'localhost'),
                    $this->config->int('DB_PORT', 3306),
                    $this->config->get('DB_DATABASE', '')
                );
            $this->pdo = new PDO($dsn, $this->config->get('DB_USERNAME', ''), $this->config->get('DB_PASSWORD', ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            $this->pdo->exec("SET time_zone = '+00:00', NAMES utf8mb4");
        }
        return $this->pdo;
    }

    public function disconnect(): void
    {
        $this->pdo = null;
        $this->depth = 0;
    }

    /** @param list<mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param list<mixed> $params
     * @return array<string,mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @param list<mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @param list<mixed> $params */
    public function exec(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    public function lastId(): int
    {
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->depth > 0) {
            return $fn();
        }
        $attempts = 0;
        while (true) {
            $attempts++;
            $pdo = $this->pdo();
            $pdo->beginTransaction();
            $this->depth = 1;
            try {
                $result = $fn();
                $pdo->commit();
                $this->depth = 0;
                return $result;
            } catch (\Throwable $e) {
                $this->depth = 0;
                if ($pdo->inTransaction()) {
                    try {
                        $pdo->rollBack();
                    } catch (PDOException) {
                        // connection lost; nothing to roll back
                    }
                }
                if ($e instanceof PDOException && $attempts < 3 && self::isRetryable($e)) {
                    usleep(random_int(10_000, 60_000));
                    continue;
                }
                throw $e;
            }
        }
    }

    private static function isRetryable(PDOException $e): bool
    {
        $code = (int) ($e->errorInfo[1] ?? 0);
        return $code === 1213 || $code === 1205; // deadlock, lock wait timeout
    }

    public static function isDuplicateKey(PDOException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
}
