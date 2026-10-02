<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Application;
use App\Http\Request;
use PHPUnit\Framework\Assert;

/** In-process HTTP client with a cookie jar. Every response is validated against openapi.yaml. */
final class TestClient
{
    public ?string $csrf = null;
    /** @var array<string,string> */
    public array $cookies = [];
    public string $ip = '203.0.113.10';
    public ?string $origin = null;
    /** @var array<string,true> "METHOD /template" of every spec operation that was exercised */
    public static array $covered = [];

    public function __construct(private Application $app, private bool $contractCheck = true)
    {
    }

    /** @param array<string,mixed>|string|null $body @param array<string,string> $headers */
    public function request(string $method, string $path, array|string|null $body = null, array $headers = [], bool $withCsrf = true): TestResponse
    {
        $query = [];
        if (str_contains($path, '?')) {
            [$path, $qs] = explode('?', $path, 2);
            parse_str($qs, $query);
        }
        $h = array_change_key_case($headers, CASE_LOWER);
        if ($withCsrf && $this->csrf !== null && $method !== 'GET' && !isset($h['x-csrf-token'])) {
            $h['x-csrf-token'] = $this->csrf;
        }
        if ($this->origin !== null) {
            $h['origin'] = $this->origin;
        }
        $raw = is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : (string) $body;
        if ($raw !== '') {
            $h['content-type'] = 'application/json';
        }
        $req = new Request($method, $path, array_map('strval', $query), $h, $this->cookies, $raw, $this->ip);
        $res = $this->app->kernel()->handle($req);

        foreach ($res->cookies() as $line) {
            [$pair] = explode(';', $line, 2);
            [$k, $v] = explode('=', $pair, 2);
            if (str_contains($line, 'Max-Age=0') || $v === 'deleted') {
                unset($this->cookies[$k]);
            } else {
                $this->cookies[$k] = $v;
            }
        }
        $out = new TestResponse($res->status, $res->body, $res->headers, $res->cookies());
        if ($this->contractCheck) {
            $this->assertContract($method, $path, $out);
        }
        return $out;
    }

    private function assertContract(string $method, string $path, TestResponse $r): void
    {
        $m = OpenApiValidator::get()->matchPath($path);
        if ($m !== null) {
            self::$covered[$method . ' ' . $m[0]] = true;
        }
        foreach (OpenApiValidator::get()->check($method, $path, $r->status, $r->body) as $p) {
            if (str_starts_with($p, 'UNDOCUMENTED:')) {
                $key = substr($p, strlen('UNDOCUMENTED:')) . ' ' . ($r->errorCode() ?? '-');
                if (getenv('COLLECT_GAPS') === '1') {
                    file_put_contents(sys_get_temp_dir() . '/training-collected-gaps.txt', $key . "\n", FILE_APPEND);
                    continue;
                }
                $known = require __DIR__ . '/../known_contract_gaps.php';
                [, , $status, $code] = explode(' ', $key, 4);
                Assert::assertTrue(
                    in_array($key, $known, true) || in_array("* * $status $code", $known, true),
                    "Response not documented in openapi.yaml and not in tests/known_contract_gaps.php: $key"
                );
                continue;
            }
            if (str_contains($p, 'unknown path') && $r->status === 404 && $r->errorCode() === 'NOT_FOUND') {
                continue;   // framework-level 404 for routes outside the contract
            }
            Assert::fail('OpenAPI contract violation: ' . $p . "\nBody: " . $r->body);
        }
    }

    /** @param array<string,string> $headers */
    public function get(string $path, array $headers = []): TestResponse
    {
        return $this->request('GET', $path, null, $headers);
    }

    /** @param array<string,mixed>|string|null $body @param array<string,string> $headers */
    public function post(string $path, array|string|null $body = null, array $headers = []): TestResponse
    {
        return $this->request('POST', $path, $body, $headers);
    }

    /** @param array<string,mixed>|string|null $body */
    public function patch(string $path, array|string|null $body = null): TestResponse
    {
        return $this->request('PATCH', $path, $body);
    }

    public function login(string $email, string $password): TestResponse
    {
        $r = $this->request('POST', '/api/v1/auth/login', ['email' => $email, 'password' => $password]);
        if ($r->status === 200) {
            $this->csrf = $r->at('data.csrf_token');
        }
        return $r;
    }
}
