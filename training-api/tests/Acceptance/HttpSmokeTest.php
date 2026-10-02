<?php
declare(strict_types=1);

namespace Tests\Acceptance;

use Tests\Support\ApiTestCase;
use Tests\Support\Env;

/** Real HTTP through PHP's built-in server: front controller, SAPI request parsing, real Set-Cookie and header handling. */
final class HttpSmokeTest extends ApiTestCase
{
    /** @var resource|null */
    private $proc = null;
    private int $port = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->port = (int) substr((string) strrchr((string) stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        $env = Env::dbSettings() + [
            'APP_ENV' => 'testing', 'ENV_FILE' => '/dev/null', 'SESSION_COOKIE_SECURE' => 'true', 'SESSION_COOKIE_SAMESITE' => 'Lax',
            'FRONTEND_ORIGINS' => 'https://train.example.nl', 'LOG_PATH' => $this->tmp . '/http.log', 'RATELIMIT_PATH' => $this->tmp . '/ratelimit',
            'INSTALL_KEY_PATH' => $this->tmp . '/install.key', 'INSTALL_LOCK_PATH' => $this->tmp . '/installed.lock',
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        ];
        $this->proc = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, '-t', dirname(__DIR__, 2) . '/public', __DIR__ . '/../Support/router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $this->tmp . '/server.out', 'a'], 2 => ['file', $this->tmp . '/server.out', 'a']],
            $pipes,
            null,
            $env
        );
        for ($i = 0; $i < 50; $i++) {
            if (@fsockopen('127.0.0.1', $this->port, $e, $s, 0.1) !== false) {
                return;
            }
            usleep(100_000);
        }
        self::fail('built-in server did not start: ' . @file_get_contents($this->tmp . '/server.out'));
    }

    protected function tearDown(): void
    {
        if (is_resource($this->proc)) {
            proc_terminate($this->proc);
            proc_close($this->proc);
        }
        parent::tearDown();
    }

    /** @return array{int,array<string,list<string>>,string} */
    private function http(string $method, string $path, ?array $json = null, array $headers = []): array
    {
        $h = array_merge($json !== null ? ['Content-Type: application/json'] : [], $headers);
        $ctx = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $h), 'content' => $json !== null ? json_encode($json) : '', 'ignore_errors' => true, 'timeout' => 10,
        ]]);
        $body = (string) @file_get_contents("http://127.0.0.1:{$this->port}$path", false, $ctx);
        $status = 0;
        $out = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            } elseif (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $out[strtolower(trim($k))][] = trim($v);
            }
        }
        return [$status, $out, $body];
    }

    public function testFullFlowOverRealHttp(): void
    {
        $this->createUser();

        [$status, , $body] = $this->http('GET', '/health');
        self::assertSame([200, '{"status":"ok","schema_version":"1.0.0","dataset_version":"1.0.0"}'], [$status, $body]);

        [$status, , $body] = $this->http('GET', '/api/v1/me');
        self::assertSame(401, $status);
        self::assertSame('UNAUTHENTICATED', json_decode($body, true)['error']['code']);

        [$status, $h, $body] = $this->http('POST', '/api/v1/auth/login', ['email' => 'a@example.nl', 'password' => self::PASSWORD]);
        self::assertSame(200, $status, $body);
        $cookie = $h['set-cookie'][0];
        self::assertMatchesRegularExpression('/^training_session=[A-Za-z0-9_-]{43}; Path=\/; HttpOnly; SameSite=Lax; Expires=.+; Max-Age=\d+; Secure$/', $cookie);
        self::assertSame('no-store', $h['cache-control'][0]);
        $csrf = json_decode($body, true)['data']['csrf_token'];
        $cookiePair = explode(';', $cookie)[0];

        [$status, , $body] = $this->http('GET', '/api/v1/me/today', null, ["Cookie: $cookiePair"]);
        self::assertSame(200, $status);
        self::assertSame('onboarding', json_decode($body, true)['data']['type']);

        [$status] = $this->http('POST', '/api/v1/me/program/start', ['start_mode' => 'beginning'], ["Cookie: $cookiePair"]);
        self::assertSame(419, $status, 'no CSRF header');
        [$status, , $body] = $this->http('POST', '/api/v1/me/program/start', ['start_mode' => 'beginning'], ["Cookie: $cookiePair", "X-CSRF-Token: $csrf"]);
        self::assertSame(201, $status, $body);
        self::assertSame(1, json_decode($body, true)['data']['assignment']['position']);

        [$status, $h] = $this->http('GET', '/api/v1/me', null, ["Cookie: $cookiePair", 'Origin: https://train.example.nl']);
        self::assertSame('https://train.example.nl', $h['access-control-allow-origin'][0]);
        self::assertSame('true', $h['access-control-allow-credentials'][0]);
        [, $h] = $this->http('GET', '/api/v1/me', null, ["Cookie: $cookiePair", 'Origin: https://evil.example.com']);
        self::assertArrayNotHasKey('access-control-allow-origin', $h);

        [$status, $h] = $this->http('OPTIONS', '/api/v1/me/program/start', null, ['Origin: https://train.example.nl', 'Access-Control-Request-Method: POST']);
        self::assertSame(204, $status);
        self::assertStringContainsString('X-CSRF-Token', $h['access-control-allow-headers'][0]);

        [$status, , $body] = $this->http('GET', '/api/v1/nope');
        self::assertSame([404, 'NOT_FOUND'], [$status, json_decode($body, true)['error']['code']]);

        [$status, $h] = $this->http('POST', '/api/v1/auth/logout', null, ["Cookie: $cookiePair", "X-CSRF-Token: $csrf"]);
        self::assertSame(204, $status);
        self::assertStringContainsString('Max-Age=0', $h['set-cookie'][0]);
        [$status] = $this->http('GET', '/api/v1/me', null, ["Cookie: $cookiePair"]);
        self::assertSame(401, $status);
    }

    public function testInstallPhpIsNeutralWithoutKeyAndNeverLeaksOverHttp(): void
    {
        [$status, , $body] = $this->http('GET', '/install.php');
        self::assertSame(404, $status);
        self::assertStringNotContainsString(self::PASSWORD, $body);
        self::assertStringNotContainsString('DB_PASSWORD', $body);
        [$status] = $this->http('POST', '/install.php', null);
        self::assertSame(404, $status);
    }

    public function testInstallPhpWithKeyOverHttpAndClosedAfterwards(): void
    {
        $key = bin2hex(random_bytes(8));
        file_put_contents($this->tmp . '/install.key', $key);
        [$status, $h, $body] = $this->http('GET', '/install.php');
        self::assertSame(200, $status);
        self::assertStringContainsString('Installatiesleutel', $body);
        self::assertStringContainsString('text/html', $h['content-type'][0]);
        self::assertSame('DENY', $h['x-frame-options'][0]);
        file_put_contents($this->tmp . '/installed.lock', '{}');
        [$status] = $this->http('GET', '/install.php');
        self::assertSame(404, $status);
    }

    public function testErrorsNeverLeakInternalsOverHttp(): void
    {
        $this->db->pdo()->exec('RENAME TABLE app_meta TO app_meta_gone');
        try {
            [$status, , $body] = $this->http('GET', '/health');
            self::assertSame([503, '{"status":"error"}'], [$status, $body]);
        } finally {
            $this->db->pdo()->exec('RENAME TABLE app_meta_gone TO app_meta');
        }
    }
}
