<?php
declare(strict_types=1);

namespace App\Auth;

use App\Support\Clock;
use App\Support\Config;
use App\Support\Db;
use DateInterval;

/**
 * Database backed opaque sessions (BR-170/171). The raw token only ever lives in the cookie;
 * only SHA-256 hashes of session and CSRF tokens are stored.
 */
final class SessionService
{
    private const TOUCH_INTERVAL_SECONDS = 300;

    public function __construct(private Db $db, private Config $config, private Clock $clock)
    {
    }

    public function cookieName(): string
    {
        return $this->config->get('SESSION_COOKIE_NAME', 'training_session') ?? 'training_session';
    }

    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return array{token:string,csrf:string,expires:\DateTimeImmutable} */
    public function create(int $userId): array
    {
        $token = self::newToken();
        $csrf = self::newToken();
        $now = $this->clock->now();
        $expires = $now->add(new DateInterval('P' . max(1, $this->config->int('SESSION_TTL_DAYS', 30)) . 'D'));
        $this->db->exec(
            'INSERT INTO auth_sessions (user_id, token_hash, csrf_token_hash, expires_at, last_seen_at, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, self::hashToken($token), self::hashToken($csrf), Clock::toDb($expires), Clock::toDb($now), Clock::toDb($now)]
        );
        return ['token' => $token, 'csrf' => $csrf, 'expires' => $expires];
    }

    public function authenticate(?string $token): ?AuthContext
    {
        if ($token === null || $token === '' || strlen($token) > 128) {
            return null;
        }
        $hash = self::hashToken($token);
        $row = $this->db->one(
            'SELECT s.id, s.user_id, s.csrf_token_hash, s.expires_at, s.last_seen_at, u.public_id
               FROM auth_sessions s JOIN users u ON u.id = s.user_id WHERE s.token_hash = ?',
            [$hash]
        );
        if ($row === null) {
            return null;
        }
        $now = $this->clock->now();
        if (Clock::fromDb((string) $row['expires_at']) <= $now) {
            $this->db->exec('DELETE FROM auth_sessions WHERE id = ?', [$row['id']]);
            return null;
        }
        if ($now->getTimestamp() - Clock::fromDb((string) $row['last_seen_at'])->getTimestamp() > self::TOUCH_INTERVAL_SECONDS) {
            $this->db->exec('UPDATE auth_sessions SET last_seen_at = ? WHERE id = ?', [Clock::toDb($now), $row['id']]);
        }
        return new AuthContext((int) $row['user_id'], (string) $row['public_id'], (int) $row['id'], $hash, (string) $row['csrf_token_hash']);
    }

    public function verifyCsrf(AuthContext $ctx, ?string $presented): bool
    {
        return $presented !== null && $presented !== '' && hash_equals($ctx->csrfTokenHash, self::hashToken($presented));
    }

    /** Rotate and return the new raw CSRF token (GET /auth/csrf). */
    public function rotateCsrf(AuthContext $ctx): string
    {
        $csrf = self::newToken();
        $this->db->exec('UPDATE auth_sessions SET csrf_token_hash = ? WHERE id = ?', [self::hashToken($csrf), $ctx->sessionId]);
        return $csrf;
    }

    public function destroy(AuthContext $ctx): void
    {
        $this->db->exec('DELETE FROM auth_sessions WHERE id = ?', [$ctx->sessionId]);
    }

    public function purgeExpired(): int
    {
        return $this->db->exec('DELETE FROM auth_sessions WHERE expires_at <= ?', [Clock::toDb($this->clock->now())]);
    }

    public function cookieHeader(string $token, ?\DateTimeImmutable $expires): string
    {
        $parts = [$this->cookieName() . '=' . $token, 'Path=/', 'HttpOnly', 'SameSite=' . $this->config->sessionCookieSameSite()];
        if ($expires !== null) {
            $parts[] = 'Expires=' . $expires->setTimezone(new \DateTimeZone('UTC'))->format('D, d M Y H:i:s') . ' GMT';
            $parts[] = 'Max-Age=' . max(0, $expires->getTimestamp() - $this->clock->now()->getTimestamp());
        }
        if ($this->config->sessionCookieSecure()) {
            $parts[] = 'Secure';
        }
        $domain = $this->config->get('SESSION_COOKIE_DOMAIN');
        if ($domain !== null) {
            $parts[] = 'Domain=' . $domain;
        }
        return implode('; ', $parts);
    }

    public function expiredCookieHeader(): string
    {
        $parts = [$this->cookieName() . '=deleted', 'Path=/', 'Expires=Thu, 01 Jan 1970 00:00:00 GMT', 'Max-Age=0', 'HttpOnly', 'SameSite=' . $this->config->sessionCookieSameSite()];
        if ($this->config->sessionCookieSecure()) {
            $parts[] = 'Secure';
        }
        $domain = $this->config->get('SESSION_COOKIE_DOMAIN');
        if ($domain !== null) {
            $parts[] = 'Domain=' . $domain;
        }
        return implode('; ', $parts);
    }
}
