<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Clock;
use App\Support\Config;
use App\Support\Env;
use App\Support\Logger;
use App\Support\RateLimiter;
use PHPUnit\Framework\TestCase;

final class SupportTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/training-unit-' . bin2hex(random_bytes(5));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            is_file($f) && @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testEnvFileParsing(): void
    {
        file_put_contents($this->dir . '/.env', <<<'ENV'
        # comment
        APP_ENV=production
        QUOTED="hello world"
        SINGLE='x y'
        TRAILING=value # comment
        export EXPORTED=yes
        EMPTY=
        BAD LINE
        1BAD=x
        ENV);
        $v = Env::parseFile($this->dir . '/.env');
        self::assertSame('production', $v['APP_ENV']);
        self::assertSame('hello world', $v['QUOTED']);
        self::assertSame('x y', $v['SINGLE']);
        self::assertSame('value', $v['TRAILING']);
        self::assertSame('yes', $v['EXPORTED']);
        self::assertSame('', $v['EMPTY']);
        self::assertArrayNotHasKey('1BAD', $v);
        self::assertSame([], Env::parseFile($this->dir . '/missing'));
    }

    public function testRealEnvironmentWinsOverTheFile(): void
    {
        file_put_contents($this->dir . '/.env', "TT_SAMPLE=from-file\n");
        putenv('TT_SAMPLE=from-env');
        try {
            self::assertSame('from-env', Env::load($this->dir . '/.env')['TT_SAMPLE']);
        } finally {
            putenv('TT_SAMPLE');
        }
        self::assertSame('from-file', Env::load($this->dir . '/.env')['TT_SAMPLE']);
    }

    public function testConfigAccessors(): void
    {
        $c = new Config(['A' => '5', 'B' => 'true', 'C' => 'x', 'D' => 'a, b ,,c', 'E' => '', 'P' => 'rel/dir'], '/base');
        self::assertSame(5, $c->int('A', 1));
        self::assertSame(1, $c->int('C', 1));
        self::assertTrue($c->bool('B', false));
        self::assertFalse($c->bool('MISSING', false));
        self::assertSame(['a', 'b', 'c'], $c->list('D'));
        self::assertSame('dflt', $c->get('E', 'dflt'));
        self::assertSame('/base/rel/dir', $c->path('P', 'x'));
        self::assertSame('/abs', (new Config(['P' => '/abs'], '/base'))->path('P', 'x'));
        self::assertSame('production', $c->env(), 'safe default');
        self::assertTrue($c->sessionCookieSecure(), 'secure by default');
        self::assertSame('Lax', $c->sessionCookieSameSite());
    }

    public function testConfigRejectsBadOrigins(): void
    {
        foreach (['*', 'https://x.nl/path', 'ftp://x.nl', 'x.nl', 'https://*.x.nl'] as $bad) {
            try {
                new Config(['FRONTEND_ORIGINS' => $bad], '/b');
                self::fail($bad);
            } catch (\RuntimeException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(['https://a.nl', 'http://localhost:5173'], (new Config(['FRONTEND_ORIGINS' => 'https://a.nl, http://localhost:5173'], '/b'))->list('FRONTEND_ORIGINS'));
    }

    public function testClockRoundTrip(): void
    {
        $t = new \DateTimeImmutable('2026-08-17 21:30:00.123456', new \DateTimeZone('Europe/Amsterdam'));
        self::assertSame('2026-08-17 19:30:00.123456', Clock::toDb($t));
        self::assertSame('2026-08-17T19:30:00Z', Clock::toIso(Clock::toDb($t)));
        self::assertNull(Clock::toIso(null));
        Clock::freeze($t);
        try {
            self::assertSame($t->getTimestamp(), (new Clock())->now()->getTimestamp());
        } finally {
            Clock::freeze(null);
        }
    }

    public function testRateLimiterWindow(): void
    {
        $l = new RateLimiter($this->dir, new Logger(null, 'error'));
        self::assertTrue($l->attempt('k', 2, 2));
        self::assertTrue($l->attempt('k', 2, 2));
        self::assertFalse($l->attempt('k', 2, 2));
        self::assertTrue($l->attempt('other', 2, 2), 'keys are independent');
        self::assertTrue($l->tooMany('k', 2, 2));
        self::assertGreaterThanOrEqual(1, $l->retryAfter('k', 2));
        $l->clear('k');
        self::assertFalse($l->tooMany('k', 2, 2));
        $l->hit('h', 1);
        $l->hit('h', 1);
        self::assertTrue($l->tooMany('h', 2, 1));
        sleep(2);
        self::assertFalse($l->tooMany('h', 2, 1), 'hits leave the window');
    }

    public function testRateLimiterFailsOpenWhenStorageIsUnwritable(): void
    {
        $l = new RateLimiter('/proc/definitely/not/writable', new Logger(null, 'error'));
        self::assertTrue($l->attempt('k', 1, 60));
        self::assertTrue($l->attempt('k', 1, 60));
    }

    public function testRateLimiterPurge(): void
    {
        $l = new RateLimiter($this->dir, new Logger(null, 'error'));
        $l->hit('old', 60);
        foreach (glob($this->dir . '/*.json') as $f) {
            touch($f, time() - 3 * 86400);
        }
        $l->hit('new', 60);
        self::assertSame(1, $l->purge(86400));
        self::assertCount(1, glob($this->dir . '/*.json'));
    }

    public function testLoggerLevelsAndFile(): void
    {
        $file = $this->dir . '/x.log';
        $l = new Logger($file, 'warning');
        $l->info('hidden');
        $l->warning('shown', ['k' => 'v']);
        $l->error('also');
        $log = (string) file_get_contents($file);
        self::assertStringNotContainsString('hidden', $log);
        self::assertStringContainsString('WARNING shown {"k":"v"}', $log);
        self::assertStringContainsString('ERROR also', $log);
    }
}
