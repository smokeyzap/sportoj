<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Http\ApiException;
use App\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    private function globals(array $server): Request
    {
        $_SERVER = $server + ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/health', 'REMOTE_ADDR' => '203.0.113.5'];
        return Request::fromGlobals(false);
    }

    public function testForwardedForIsIgnoredUnlessTrusted(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/x', 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 198.51.100.9'];
        self::assertSame('10.0.0.1', Request::fromGlobals(false)->ip);
        self::assertSame('198.51.100.9', Request::fromGlobals(true)->ip, 'last hop = appended by our own proxy; the first hop is client controlled');
        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip';
        self::assertSame('10.0.0.1', Request::fromGlobals(true)->ip);
    }

    public function testPathQueryHeadersAndCookies(): void
    {
        $_COOKIE = ['training_session' => 'abc'];
        $_GET = ['page' => '2'];
        $r = $this->globals(['REQUEST_URI' => '/api/v1/me/history?page=2', 'HTTP_X_CSRF_TOKEN' => 't', 'CONTENT_TYPE' => 'application/json']);
        self::assertSame('/api/v1/me/history', $r->path);
        self::assertSame('t', $r->header('X-CSRF-Token'));
        self::assertSame('application/json', $r->header('content-type'));
        self::assertSame('abc', $r->cookies['training_session']);
        self::assertSame(['page' => '2'], $r->query);
        $_COOKIE = $_GET = [];
    }

    public function testEncodedSlashDoesNotSneakIntoAPathParameter(): void
    {
        self::assertSame('/api/v1/workouts/a/b', $this->globals(['REQUEST_URI' => '/api/v1/workouts/a%2Fb'])->path);
    }

    public function testJsonObjectParsing(): void
    {
        $r = fn (string $b) => new Request('POST', '/x', [], [], [], $b);
        self::assertSame([], $r('')->jsonObject());
        self::assertSame([], $r('  ')->jsonObject());
        self::assertSame(['a' => 1], $r('{"a":1}')->jsonObject());
        self::assertSame([], $r('{}')->jsonObject());
        foreach (['[1,2]', '"str"', '5', '{bad', 'null'] as $bad) {
            try {
                $r($bad)->jsonObject();
                self::fail($bad);
            } catch (ApiException $e) {
                self::assertSame('VALIDATION_ERROR', $e->errorCode);
            }
        }
    }
}
