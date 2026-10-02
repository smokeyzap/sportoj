<?php
declare(strict_types=1);

namespace App\Support;

/** File logger. Callers must never pass passwords, raw tokens or credentials (INSTALLATION_REQUIREMENTS section 14). */
final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    public function __construct(private ?string $path, private string $level = 'warning')
    {
    }

    /** @param array<string,mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        $min = self::LEVELS[$this->level] ?? 2;
        if ((self::LEVELS[$level] ?? 3) < $min) {
            return;
        }
        $line = gmdate('Y-m-d\TH:i:s\Z') . ' ' . strtoupper($level) . ' ' . $message;
        if ($context !== []) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }
        $line .= "\n";
        if ($this->path === null || @file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log(rtrim($line));
        }
    }

    /** @param array<string,mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }
}
