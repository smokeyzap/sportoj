<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Application;
use App\Install\Installer;
use App\Support\Clock;
use App\Support\Config;
use App\Support\Db;
use PHPUnit\Framework\TestCase;

abstract class ApiTestCase extends TestCase
{
    protected const PASSWORD = 'correct horse battery';

    protected Application $app;
    protected Db $db;
    protected string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/training-test-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/ratelimit', 0777, true);
        Clock::freeze(null);
        $this->app = $this->makeApp();
        $this->db = $this->app->db();
        $this->resetUserData();
    }

    protected function tearDown(): void
    {
        Clock::freeze(null);
        $this->db->pdo()->exec('DROP TRIGGER IF EXISTS tt_fail_session_complete');
        $this->db->pdo()->exec('DROP TRIGGER IF EXISTS tt_fail_extend');
        $this->rm($this->tmp);
    }

    /** @param array<string,string> $overrides */
    protected function makeApp(array $overrides = []): Application
    {
        $env = Env::dbSettings() + [
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'false',
            'SESSION_COOKIE_SECURE' => 'false',
            'FRONTEND_ORIGINS' => 'https://train.example.nl',
            'LOG_PATH' => $this->tmp . '/app.log',
            'RATELIMIT_PATH' => $this->tmp . '/ratelimit',
            'INSTALL_LOCK_PATH' => $this->tmp . '/installed.lock',
            'INSTALL_KEY_PATH' => $this->tmp . '/install.key',
        ];
        return new Application(new Config($overrides + $env, dirname(__DIR__, 2)));
    }

    protected function resetUserData(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['workout_sessions', 'workout_assignments', 'user_program_blocks', 'user_programs', 'auth_sessions', 'users'] as $t) {
            $pdo->exec("TRUNCATE TABLE `$t`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @return array<string,mixed> */
    protected function createUser(string $email = 'a@example.nl', string $name = 'User A', string $timezone = 'Europe/Amsterdam'): array
    {
        $installer = new Installer($this->app->config, $this->db, new Clock());
        return $installer->createUser($name, $email, self::PASSWORD, $timezone);
    }

    /** A logged-in client for a freshly created user. */
    protected function loggedIn(string $email = 'a@example.nl', string $name = 'User A', ?Application $app = null): TestClient
    {
        $this->createUser($email, $name);
        $client = new TestClient($app ?? $this->app);
        $res = $client->login($email, self::PASSWORD);
        self::assertSame(200, $res->status, 'login failed: ' . $res->body);
        return $client;
    }

    protected function anonymous(): TestClient
    {
        return new TestClient($this->app);
    }

    // ---- program helpers (black-box: only through the API) ----

    protected function startProgram(TestClient $c, string $mode = 'beginning', ?int $week = null, ?int $day = null): TestResponse
    {
        $body = ['start_mode' => $mode];
        if ($mode === 'position') {
            $body += ['original_week' => $week, 'day_sequence' => $day];
        }
        $r = $c->post('/api/v1/me/program/start', $body);
        self::assertSame(201, $r->status, 'start failed: ' . $r->body);
        return $r;
    }

    /** @return array<string,mixed> */
    protected function today(TestClient $c): array
    {
        $r = $c->get('/api/v1/me/today');
        self::assertSame(200, $r->status, $r->body);
        return $r->data();
    }

    /** @return array<string,mixed> */
    protected function programState(TestClient $c): array
    {
        $r = $c->get('/api/v1/me/program');
        self::assertSame(200, $r->status, $r->body);
        return $r->data();
    }

    /** Public id of the assignment at block sequence / cycle / position of the current run. */
    protected function aid(TestClient $c, int $block, int $cycle, int $pos): string
    {
        foreach ($this->programState($c)['blocks'] as $b) {
            if ($b['block']['sequence'] === $block) {
                foreach ($b['cycles'] as $cy) {
                    if ($cy['number'] === $cycle) {
                        foreach ($cy['assignments'] as $a) {
                            if ($a['position'] === $pos) {
                                return $a['id'];
                            }
                        }
                    }
                }
            }
        }
        self::fail("assignment $block/$cycle/$pos not found");
    }

    /** @return array<string,mixed> */
    protected function assignment(TestClient $c, string $id): array
    {
        $r = $c->get("/api/v1/workout-assignments/$id");
        self::assertSame(200, $r->status, $r->body);
        return $r->data();
    }

    protected function statusOf(TestClient $c, string $id): string
    {
        return $this->assignment($c, $id)['status'];
    }

    protected function act(TestClient $c, string $id, string $action, ?array $body = null): TestResponse
    {
        return $c->post("/api/v1/workout-assignments/$id/$action", $body);
    }

    protected function complete(TestClient $c, string $id, ?string $notes = null): TestResponse
    {
        $r = $this->act($c, $id, 'complete', $notes === null ? null : ['notes' => $notes]);
        self::assertSame(200, $r->status, 'complete failed: ' . $r->body);
        return $r;
    }

    protected function skipA(TestClient $c, string $id): TestResponse
    {
        $r = $this->act($c, $id, 'skip');
        self::assertSame(200, $r->status, 'skip failed: ' . $r->body);
        return $r;
    }

    protected function reopenA(TestClient $c, string $id): TestResponse
    {
        $r = $this->act($c, $id, 'reopen');
        self::assertSame(200, $r->status, 'reopen failed: ' . $r->body);
        return $r;
    }

    /** Complete positions of one cycle in order. */
    protected function completeCycle(TestClient $c, int $block, int $cycle, array $positions = [1, 2, 3, 4, 5, 6]): void
    {
        foreach ($positions as $p) {
            $this->complete($c, $this->aid($c, $block, $cycle, $p));
        }
    }

    /** Complete every pending assignment of a block (cycles 1..target). */
    protected function completeBlock(TestClient $c, int $block, int $cycles): void
    {
        for ($cy = 1; $cy <= $cycles; $cy++) {
            $this->completeCycle($c, $block, $cy);
        }
    }

    /**
     * Run $child in $n forked processes at (nearly) the same moment and collect what each returns.
     * Children report through a file and kill themselves, so they never close the parent's database socket.
     *
     * @param callable(int):mixed $child
     * @return list<mixed>
     */
    protected function parallel(int $n, callable $child): array
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl not available');
        }
        $gate = $this->tmp . '/gate';
        $pids = [];
        for ($i = 0; $i < $n; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                while (!is_file($gate)) {
                    usleep(500);
                }
                try {
                    $result = $child($i);
                } catch (\Throwable $e) {
                    $result = 'EXCEPTION: ' . $e->getMessage();
                }
                file_put_contents($this->tmp . "/result-$i", serialize($result));
                posix_kill(posix_getpid(), SIGKILL);
            }
            $pids[$i] = $pid;
        }
        usleep(150_000);   // let the children reach the gate
        touch($gate);
        $out = [];
        foreach ($pids as $i => $pid) {
            pcntl_waitpid($pid, $status);
            $out[] = unserialize((string) file_get_contents($this->tmp . "/result-$i"));
        }
        return $out;
    }

    /** @return array<string,mixed> */
    protected function rowOf(string $sql, array $params = []): array
    {
        return $this->db->one($sql, $params) ?? [];
    }

    private function rm(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = "$dir/$f";
            is_dir($p) ? $this->rm($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
