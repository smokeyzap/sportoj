<?php
declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class Config
{
    /** @param array<string,string> $env */
    public function __construct(private array $env, public readonly string $basePath)
    {
        $this->validate();
    }

    public static function fromEnvFile(string $basePath): self
    {
        $file = getenv('ENV_FILE') ?: $basePath . '/.env';
        return new self(Env::load($file), $basePath);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $v = $this->env[$key] ?? null;
        return ($v === null || $v === '') ? $default : $v;
    }

    public function bool(string $key, bool $default): bool
    {
        $v = $this->get($key);
        if ($v === null) {
            return $default;
        }
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $key, int $default): int
    {
        $v = $this->get($key);
        return $v !== null && preg_match('/^-?\d+$/', $v) === 1 ? (int) $v : $default;
    }

    /** @return list<string> */
    public function list(string $key): array
    {
        $v = $this->get($key, '') ?? '';
        return array_values(array_filter(array_map('trim', explode(',', $v)), static fn ($x) => $x !== ''));
    }

    public function env(): string
    {
        return $this->get('APP_ENV', 'production') ?? 'production';
    }

    public function debug(): bool
    {
        return $this->bool('APP_DEBUG', false);
    }

    public function path(string $key, string $relativeDefault): string
    {
        $p = $this->get($key) ?? $relativeDefault;
        return str_starts_with($p, '/') ? $p : $this->basePath . '/' . $p;
    }

    public function sessionCookieSecure(): bool
    {
        return $this->bool('SESSION_COOKIE_SECURE', true);
    }

    public function sessionCookieSameSite(): string
    {
        return ucfirst(strtolower($this->get('SESSION_COOKIE_SAMESITE', 'Lax') ?? 'Lax'));
    }

    private function validate(): void
    {
        $same = $this->sessionCookieSameSite();
        if (!in_array($same, ['Lax', 'Strict', 'None'], true)) {
            throw new RuntimeException('SESSION_COOKIE_SAMESITE must be Lax, Strict or None.');
        }
        if ($same === 'None' && !$this->sessionCookieSecure()) {
            throw new RuntimeException('SESSION_COOKIE_SAMESITE=None requires SESSION_COOKIE_SECURE=true.');
        }
        foreach ($this->list('FRONTEND_ORIGINS') as $origin) {
            if ($origin === '*' || preg_match('#^https?://[^/\s*]+$#i', $origin) !== 1) {
                throw new RuntimeException('FRONTEND_ORIGINS must be a comma separated list of explicit origins (no wildcard, no path).');
            }
        }
    }
}
