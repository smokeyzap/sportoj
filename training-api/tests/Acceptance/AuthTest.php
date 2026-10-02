<?php
declare(strict_types=1);

namespace Tests\Acceptance;

use App\Support\Config;
use Tests\Support\ApiTestCase;
use Tests\Support\TestClient;

/** AT-010..AT-015, BR-170..BR-176, AT-143. */
final class AuthTest extends ApiTestCase
{
    public function testAT010ValidLoginReturnsUserCsrfAndSecureCookie(): void
    {
        $app = $this->makeApp(['SESSION_COOKIE_SECURE' => 'true']);
        $this->createUser('a@example.nl', 'Anna');
        $c = new TestClient($app);
        $r = $c->login('a@example.nl', self::PASSWORD);

        self::assertSame(200, $r->status);
        self::assertSame('Anna', $r->at('data.user.name'));
        self::assertSame('a@example.nl', $r->at('data.user.email'));
        self::assertMatchesRegularExpression('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $r->at('data.user.id'));
        self::assertGreaterThanOrEqual(32, strlen((string) $r->at('data.csrf_token')));
        self::assertArrayNotHasKey('password', $r->at('data.user'));

        $cookie = $r->cookies[0];
        self::assertStringContainsString('training_session=', $cookie);
        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('Secure', $cookie);
        self::assertStringContainsString('SameSite=Lax', $cookie);

        // BR-171: only the SHA-256 of the token is stored, never the raw token (nor the raw CSRF token).
        $raw = $c->cookies['training_session'];
        $row = $this->rowOf('SELECT token_hash, csrf_token_hash FROM auth_sessions');
        self::assertSame(hash('sha256', $raw), $row['token_hash']);
        self::assertSame(hash('sha256', $r->at('data.csrf_token')), $row['csrf_token_hash']);
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM auth_sessions WHERE token_hash = ? OR csrf_token_hash = ?', [$raw, $r->at('data.csrf_token')]));
    }

    public function testCookieSecureFlagIsConfigurableAndSameSiteToo(): void
    {
        $this->createUser();
        $dev = new TestClient($this->makeApp(['SESSION_COOKIE_SECURE' => 'false', 'SESSION_COOKIE_SAMESITE' => 'Strict']));
        $cookie = $dev->login('a@example.nl', self::PASSWORD)->cookies[0];
        self::assertStringNotContainsString('Secure', $cookie);
        self::assertStringContainsString('SameSite=Strict', $cookie);

        $none = new TestClient($this->makeApp(['SESSION_COOKIE_SECURE' => 'true', 'SESSION_COOKIE_SAMESITE' => 'None', 'SESSION_COOKIE_DOMAIN' => 'api.example.nl']));
        $cookie = $none->login('a@example.nl', self::PASSWORD)->cookies[0];
        self::assertStringContainsString('SameSite=None', $cookie);
        self::assertStringContainsString('Secure', $cookie);
        self::assertStringContainsString('Domain=api.example.nl', $cookie);
    }

    public function testSameSiteNoneWithoutSecureIsRejectedAtBoot(): void
    {
        $this->expectException(\RuntimeException::class);
        new Config(['SESSION_COOKIE_SAMESITE' => 'None', 'SESSION_COOKIE_SECURE' => 'false'], __DIR__);
    }

    public function testWildcardCorsOriginIsRejectedAtBoot(): void
    {
        $this->expectException(\RuntimeException::class);
        new Config(['FRONTEND_ORIGINS' => '*'], __DIR__);
    }

    public function testAT011InvalidLoginDoesNotRevealWhichFieldIsWrong(): void
    {
        $this->createUser();
        $c = $this->anonymous();
        $wrongPassword = $c->login('a@example.nl', 'nope nope nope');
        $unknownUser = $this->anonymous()->login('ghost@example.nl', 'nope nope nope');

        self::assertSame(401, $wrongPassword->status);
        self::assertSame('INVALID_CREDENTIALS', $wrongPassword->errorCode());
        self::assertSame($wrongPassword->body, $unknownUser->body);
        self::assertSame([], $wrongPassword->cookies);
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM auth_sessions'));
    }

    public function testLoginEmailIsCaseInsensitiveAndTrimmed(): void
    {
        $this->createUser('a@example.nl');
        self::assertSame(200, $this->anonymous()->login('  A@Example.NL ', self::PASSWORD)->status);
    }

    public function testLoginValidation(): void
    {
        $c = $this->anonymous();
        self::assertSame('VALIDATION_ERROR', $c->post('/api/v1/auth/login', [])->errorCode());
        self::assertSame('VALIDATION_ERROR', $c->post('/api/v1/auth/login', ['email' => ['x'], 'password' => 'x'])->errorCode());
        self::assertSame('VALIDATION_ERROR', $c->post('/api/v1/auth/login', ['email' => 'a@example.nl', 'password' => ''])->errorCode());
    }

    public function testHugePasswordIsRejectedBeforeAnyHashing(): void
    {
        $this->createUser();
        $t = microtime(true);
        $r = $this->anonymous()->post('/api/v1/auth/login', ['email' => 'a@example.nl', 'password' => str_repeat('x', 200_000)]);
        self::assertSame(422, $r->status);
        self::assertSame('VALIDATION_ERROR', $r->errorCode());
        self::assertLessThan(0.5, microtime(true) - $t);
    }

    public function testAT012MutationRequiresValidCsrfToken(): void
    {
        $c = $this->loggedIn();
        $valid = $c->csrf;

        $c->csrf = null;
        $r = $c->patch('/api/v1/me', ['name' => 'X']);
        self::assertSame(419, $r->status);
        self::assertSame('CSRF_MISMATCH', $r->errorCode());

        $c->csrf = 'definitely-not-the-token-definitely-not-the-token';
        self::assertSame(419, $c->patch('/api/v1/me', ['name' => 'X'])->status);
        self::assertSame(419, $c->post('/api/v1/auth/logout')->status);
        self::assertSame(419, $c->post('/api/v1/me/program/start', ['start_mode' => 'beginning'])->status);
        self::assertSame('User A', $this->db->value('SELECT name FROM users'), 'rejected request must not mutate');

        $c->csrf = $valid;
        self::assertSame(200, $c->patch('/api/v1/me', ['name' => 'Renamed'])->status);
    }

    public function testCsrfCheckComesAfterAuthenticationAndOnlyAppliesToUnsafeMethods(): void
    {
        $c = $this->anonymous();
        self::assertSame(401, $c->post('/api/v1/me/program/start', ['start_mode' => 'beginning'])->status);
        $logged = $this->loggedIn();
        $logged->csrf = null;
        self::assertSame(200, $logged->get('/api/v1/me')->status);
    }

    public function testCsrfEndpointRotatesToken(): void
    {
        $c = $this->loggedIn();
        $old = $c->csrf;
        $r = $c->get('/api/v1/auth/csrf');
        self::assertSame(200, $r->status);
        $new = $r->at('data.csrf_token');
        self::assertNotSame($old, $new);

        self::assertSame(419, $c->patch('/api/v1/me', ['name' => 'X'])->status);   // still carries the old token
        $c->csrf = $new;
        self::assertSame(200, $c->patch('/api/v1/me', ['name' => 'X'])->status);
    }

    public function testAT013LogoutInvalidatesServerSideSession(): void
    {
        $c = $this->loggedIn();
        $cookieBefore = $c->cookies;
        $r = $c->post('/api/v1/auth/logout');
        self::assertSame(204, $r->status);
        self::assertSame('', (string) $r->body);
        self::assertStringContainsString('Max-Age=0', $r->cookies[0]);
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM auth_sessions'));

        $replay = new TestClient($this->app);
        $replay->cookies = $cookieBefore;
        $replay->csrf = $c->csrf;
        self::assertSame(401, $replay->get('/api/v1/me')->status);
    }

    public function testAT014ExpiredSessionIsRejectedAndStaysInvalid(): void
    {
        $c = $this->loggedIn();
        self::assertSame(200, $c->get('/api/v1/me')->status);
        $this->db->exec("UPDATE auth_sessions SET expires_at = UTC_TIMESTAMP(6) - INTERVAL 1 SECOND");
        $cookies = $c->cookies;

        $r = $c->get('/api/v1/me');
        self::assertSame(401, $r->status);
        self::assertSame('UNAUTHENTICATED', $r->errorCode());
        self::assertArrayNotHasKey('training_session', $c->cookies, 'stale cookie is cleared');

        $again = new TestClient($this->app);
        $again->cookies = $cookies;
        self::assertSame(401, $again->get('/api/v1/me')->status, 'an expired session never becomes valid again');
    }

    public function testSessionExpiryFollowsConfiguredTtl(): void
    {
        $app = $this->makeApp(['SESSION_TTL_DAYS' => '2']);
        $this->createUser();
        (new TestClient($app))->login('a@example.nl', self::PASSWORD);
        $hours = (int) $this->db->value('SELECT TIMESTAMPDIFF(HOUR, created_at, expires_at) FROM auth_sessions');
        self::assertSame(48, $hours);
    }

    public function testAT015LoginRateLimit(): void
    {
        $this->createUser();
        $c = $this->anonymous();
        for ($i = 1; $i <= 5; $i++) {
            self::assertSame(401, $c->login('a@example.nl', 'wrong wrong wrong')->status, "attempt $i");
        }
        $blocked = $c->login('a@example.nl', 'wrong wrong wrong');
        self::assertSame(429, $blocked->status);
        self::assertSame('RATE_LIMITED', $blocked->errorCode());
        self::assertNotNull($blocked->header('Retry-After'));

        // Even the right password is refused while limited, but another account/IP signal is unaffected.
        self::assertSame(429, $c->login('a@example.nl', self::PASSWORD)->status);
        $this->createUser('b@example.nl', 'User B');
        self::assertSame(200, $c->login('b@example.nl', self::PASSWORD)->status);
        $other = $this->anonymous();
        $other->ip = '198.51.100.7';
        self::assertSame(200, $other->login('a@example.nl', self::PASSWORD)->status);
    }

    public function testSuccessfulLoginResetsTheFailureCounter(): void
    {
        $this->createUser();
        $c = $this->anonymous();
        for ($i = 0; $i < 3; $i++) {
            $c->login('a@example.nl', 'wrong wrong wrong');
        }
        self::assertSame(200, $c->login('a@example.nl', self::PASSWORD)->status);
        for ($i = 0; $i < 4; $i++) {
            self::assertSame(401, $c->login('a@example.nl', 'wrong wrong wrong')->status);
        }
    }

    public function testLoginRateLimitIsConfigurable(): void
    {
        $app = $this->makeApp(['RATE_LOGIN_MAX' => '2']);
        $this->createUser();
        $c = new TestClient($app);
        $c->login('a@example.nl', 'x1');
        $c->login('a@example.nl', 'x2');
        self::assertSame(429, $c->login('a@example.nl', 'x3')->status);
    }

    public function testRateLimitStateLivesInFilesNotInTheDatabase(): void
    {
        $this->createUser();
        $this->anonymous()->login('a@example.nl', 'wrong');
        self::assertCount(2, glob($this->tmp . '/ratelimit/*.json') ?: []);   // pair + ip bucket
    }

    public function testAuthenticatedApiRateLimit(): void
    {
        $app = $this->makeApp(['RATE_API_MAX' => '3']);
        $this->createUser();
        $c = new TestClient($app);
        $c->login('a@example.nl', self::PASSWORD);
        for ($i = 0; $i < 3; $i++) {
            self::assertSame(200, $c->get('/api/v1/me')->status);
        }
        $r = $c->get('/api/v1/me');
        self::assertSame(429, $r->status);
        self::assertSame('RATE_LIMITED', $r->errorCode());
    }

    public function testMutationRateLimit(): void
    {
        $app = $this->makeApp(['RATE_MUTATION_MAX' => '2']);
        $this->createUser();
        $c = new TestClient($app);
        $c->login('a@example.nl', self::PASSWORD);
        self::assertSame(200, $c->patch('/api/v1/me', ['name' => 'A1'])->status);
        self::assertSame(200, $c->patch('/api/v1/me', ['name' => 'A2'])->status);
        self::assertSame(429, $c->patch('/api/v1/me', ['name' => 'A3'])->status);
        self::assertSame(200, $c->get('/api/v1/me')->status, 'reads are not counted against the mutation budget');
    }

    public function testProtectedEndpointsRequireLogin(): void
    {
        $c = $this->anonymous();
        foreach (['/api/v1/me', '/api/v1/me/today', '/api/v1/me/program', '/api/v1/me/history', '/api/v1/auth/csrf',
            '/api/v1/workout-assignments/01AAAAAAAAAAAAAAAAAAAAAAAA', '/api/v1/workouts/01AAAAAAAAAAAAAAAAAAAAAAAA'] as $path) {
            $r = $c->get($path);
            self::assertSame(401, $r->status, $path);
            self::assertSame('UNAUTHENTICATED', $r->errorCode(), $path);
        }
    }

    public function testGarbageCookieIsRejected(): void
    {
        $c = $this->anonymous();
        $c->cookies['training_session'] = str_repeat('A', 43);
        self::assertSame(401, $c->get('/api/v1/me')->status);
        $c->cookies['training_session'] = str_repeat('A', 5000);
        self::assertSame(401, $c->get('/api/v1/me')->status);
    }

    public function testGetAndPatchMe(): void
    {
        $c = $this->loggedIn();
        self::assertSame('Europe/Amsterdam', $c->get('/api/v1/me')->at('data.timezone'));

        $r = $c->patch('/api/v1/me', ['name' => '  Nieuwe Naam ', 'timezone' => 'America/New_York']);
        self::assertSame(200, $r->status);
        self::assertSame('Nieuwe Naam', $r->at('data.name'));
        self::assertSame('America/New_York', $r->at('data.timezone'));

        self::assertSame(422, $c->patch('/api/v1/me', [])->status);
        self::assertSame(422, $c->patch('/api/v1/me', ['name' => ''])->status);
        self::assertSame(422, $c->patch('/api/v1/me', ['name' => str_repeat('x', 121)])->status);
        self::assertSame(422, $c->patch('/api/v1/me', ['timezone' => 'Mars/Olympus'])->status);
        self::assertSame(422, $c->patch('/api/v1/me', ['name' => 5])->status);
        $r = $c->patch('/api/v1/me', ['name' => 'Ok']);
        self::assertSame(200, $r->status);
        self::assertSame('America/New_York', $r->at('data.timezone'), 'unspecified fields stay untouched');
    }

    public function testPasswordUsesArgon2idAndIsRehashedOnLogin(): void
    {
        $this->createUser();
        self::assertStringStartsWith('$argon2id$', (string) $this->db->value('SELECT password FROM users'));

        $this->db->exec('UPDATE users SET password = ?', [password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4])]);
        self::assertStringStartsWith('$2y$', (string) $this->db->value('SELECT password FROM users'));
        self::assertSame(200, $this->anonymous()->login('a@example.nl', self::PASSWORD)->status);
        self::assertStringStartsWith('$argon2id$', (string) $this->db->value('SELECT password FROM users'), 'BR-175 rehash after successful login');
        self::assertSame(200, $this->anonymous()->login('a@example.nl', self::PASSWORD)->status);
    }

    public function testSecretsAreNeverLogged(): void
    {
        $this->createUser();
        $c = $this->anonymous();
        $c->login('a@example.nl', 'my-secret-wrong-password');
        $good = $this->anonymous();
        $good->login('a@example.nl', self::PASSWORD);
        $c->get('/api/v1/nonexistent');
        $log = (string) @file_get_contents($this->tmp . '/app.log');
        self::assertStringContainsString('login failed', $log);
        self::assertStringNotContainsString('my-secret-wrong-password', $log);
        self::assertStringNotContainsString(self::PASSWORD, $log);
        self::assertStringNotContainsString($good->cookies['training_session'], $log);
        self::assertStringNotContainsString($good->csrf, $log);
    }

    public function testAT143CorsOnlyForExplicitOriginsAndNeverWildcard(): void
    {
        $c = $this->loggedIn();
        $c->origin = 'https://train.example.nl';
        $r = $c->get('/api/v1/me');
        self::assertSame('https://train.example.nl', $r->header('Access-Control-Allow-Origin'));
        self::assertSame('true', $r->header('Access-Control-Allow-Credentials'));
        self::assertStringContainsString('Origin', (string) $r->header('Vary'));

        $c->origin = 'https://evil.example.com';
        $r = $c->get('/api/v1/me');
        self::assertNull($r->header('Access-Control-Allow-Origin'));
        self::assertNull($r->header('Access-Control-Allow-Credentials'));
        self::assertStringContainsString('Origin', (string) $r->header('Vary'));

        $c->origin = null;
        self::assertNull($c->get('/api/v1/me')->header('Access-Control-Allow-Origin'));
    }

    public function testCorsPreflight(): void
    {
        $c = $this->anonymous();
        $c->origin = 'https://train.example.nl';
        $r = $c->request('OPTIONS', '/api/v1/me/program/start', null, ['Access-Control-Request-Method' => 'POST']);
        self::assertSame(204, $r->status);
        self::assertSame('https://train.example.nl', $r->header('Access-Control-Allow-Origin'));
        self::assertStringContainsString('X-CSRF-Token', (string) $r->header('Access-Control-Allow-Headers'));
        self::assertStringContainsString('PATCH', (string) $r->header('Access-Control-Allow-Methods'));
        self::assertNotSame('*', $r->header('Access-Control-Allow-Origin'));

        $c->origin = 'https://evil.example.com';
        self::assertNull($c->request('OPTIONS', '/api/v1/me')->header('Access-Control-Allow-Origin'));
    }

    public function testErrorEnvelopeForUnknownRouteAndMethod(): void
    {
        $c = $this->loggedIn();
        $r = $c->get('/api/v1/does-not-exist');
        self::assertSame(404, $r->status);
        self::assertSame('NOT_FOUND', $r->errorCode());
        self::assertIsString($r->at('error.message'));

        $r = $c->request('DELETE', '/api/v1/me');
        self::assertSame(405, $r->status);
        self::assertSame('METHOD_NOT_ALLOWED', $r->errorCode());
        self::assertStringContainsString('GET', (string) $r->header('Allow'));
        self::assertSame(404, $c->get('/api/v2/me')->status, 'only /api/v1 exists (AT-142)');
    }

    public function testUnhandledErrorsGiveGenericEnvelopeWithoutDetails(): void
    {
        $app = $this->makeApp(['DB_PASSWORD' => 'definitely-wrong']);
        $r = (new TestClient($app, false))->get('/health');
        self::assertSame(503, $r->status);
        self::assertSame('{"status":"error"}', $r->body);

        $r = (new TestClient($app, false))->login('a@example.nl', 'x');
        self::assertSame(500, $r->status);
        self::assertSame('INTERNAL_ERROR', $r->errorCode());
        self::assertStringNotContainsString('definitely-wrong', (string) $r->body);
        self::assertStringNotContainsString('SQLSTATE', (string) $r->body);
        self::assertNull($r->at('error.details'));
    }

    public function testHealthIsPublicAndMinimal(): void
    {
        $r = $this->anonymous()->get('/health');
        self::assertSame(200, $r->status);
        self::assertSame(['status' => 'ok', 'schema_version' => '1.0.0', 'dataset_version' => '1.0.0'], $r->json());
    }

    public function testSecurityHeaders(): void
    {
        $r = $this->anonymous()->get('/health');
        self::assertSame('nosniff', $r->header('X-Content-Type-Options'));
        self::assertSame('no-store', $r->header('Cache-Control'));
        self::assertStringContainsString('application/json', (string) $r->header('Content-Type'));
    }

    public function testLoginHasNoCsrfRequirement(): void
    {
        // Approved interpretation 9: no login CSRF.
        $this->createUser();
        $c = $this->anonymous();
        $c->csrf = null;
        self::assertSame(200, $c->login('a@example.nl', self::PASSWORD)->status);
    }
}
