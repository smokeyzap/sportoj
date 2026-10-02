<?php
declare(strict_types=1);

namespace Tests\Acceptance;

use App\Application;
use App\Http\Request;
use App\Install\InstallException;
use App\Install\Installer;
use App\Install\WebInstaller;
use App\Support\Clock;
use App\Support\Config;
use App\Support\Db;
use App\Support\Logger;
use App\Support\RateLimiter;
use PHPUnit\Framework\TestCase;
use Tests\Support\Env;

/** AT-001 and INSTALLATION_REQUIREMENTS section 12 (installer, key, lock, CLI). Uses its own scratch database. */
final class InstallTest extends TestCase
{
    private const DB = 'training_test_install';
    private string $tmp;
    private Config $config;
    private Db $db;
    private Installer $installer;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/training-install-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/ratelimit', 0777, true);
        Env::recreateDatabase(self::DB);
        $this->config = $this->config();
        $this->db = new Db($this->config);
        $this->installer = new Installer($this->config, $this->db, new Clock());
    }

    protected function tearDown(): void
    {
        foreach (array_merge(glob($this->tmp . '/ratelimit/*') ?: [], glob($this->tmp . '/*') ?: []) as $f) {
            is_file($f) && @unlink($f);
        }
        @rmdir($this->tmp . '/ratelimit');
        @rmdir($this->tmp);
    }

    /** @param array<string,string> $extra */
    private function config(array $extra = []): Config
    {
        return new Config($extra + Env::dbSettings(self::DB) + [
            'APP_ENV' => 'testing',
            'INSTALL_KEY_PATH' => $this->tmp . '/install.key',
            'INSTALL_LOCK_PATH' => $this->tmp . '/installed.lock',
            'LOG_PATH' => $this->tmp . '/app.log',
        ], dirname(__DIR__, 2));
    }

    private function web(): WebInstaller
    {
        return new WebInstaller($this->installer, $this->config, new RateLimiter($this->tmp . '/ratelimit', new Logger(null, 'error')), new Logger($this->tmp . '/app.log', 'warning'));
    }

    /** @param array<string,string> $form */
    private function post(array $form, string $ip = '203.0.113.9'): \App\Http\Response
    {
        return $this->web()->handle(new Request('POST', '/install.php', [], ['content-type' => 'application/x-www-form-urlencoded'], [], http_build_query($form), $ip));
    }

    private function fullInstall(): void
    {
        $this->installer->installSchema();
        $this->installer->seed();
        $this->installer->createUser('Admin', 'admin@example.nl', 'a-long-enough-password', 'Europe/Amsterdam');
    }

    // ---- AT-001 ----

    public function testAT001SchemaInstallsCleanlyOnAnEmptyDatabase(): void
    {
        self::assertSame('created', $this->installer->installSchema());
        $tables = array_map(static fn ($r) => array_values($r)[0], $this->db->all('SHOW TABLES'));
        sort($tables);
        self::assertSame(['app_meta', 'app_schema_versions', 'auth_sessions', 'block_workouts', 'programs', 'training_blocks', 'user_program_blocks', 'user_programs',
            'users', 'workout_assignments', 'workout_exercises', 'workout_sessions', 'workout_templates'], $tables);
        self::assertSame('1.0.0', $this->db->value('SELECT version FROM app_schema_versions'));

        // every constraint and index of the baseline exists (counted from the specification file itself)
        $spec = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/spec/schema.sql');
        $q = fn (string $sql): int => (int) $this->db->value($sql, [self::DB]);
        self::assertSame(preg_match_all('/CONSTRAINT \w+ FOREIGN KEY/', $spec), $q("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'"));
        self::assertSame(preg_match_all('/^\s+UNIQUE KEY /m', $spec) + 13, $q("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND CONSTRAINT_TYPE IN ('UNIQUE','PRIMARY KEY')"), 'unique keys + one primary key per table');
        // + 1: MariaDB implements the JSON column protocol_config as LONGTEXT with an automatic JSON_VALID check.
        self::assertSame(preg_match_all('/CONSTRAINT chk_/', $spec) + 1, $q('SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ?'));
        self::assertSame(preg_match_all('/^\s+KEY idx_/m', $spec), $q('SELECT COUNT(DISTINCT TABLE_NAME, INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND NON_UNIQUE = 1'));
        self::assertSame(13, $q("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND ENGINE = 'InnoDB' AND TABLE_COLLATION = 'utf8mb4_unicode_ci'"));
    }

    public function testShippedSchemaDiffersFromTheSpecByExactlyTheDocumentedForeignKeyAction(): void
    {
        $spec = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/spec/schema.sql');
        $ours = (string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');
        $ours = preg_replace('/\A(?:-- DEVIATION.*\n|-- .*\n)+(?=-- Trainingsapp)/', '', $ours);
        $expected = str_replace(
            'REFERENCES workout_assignments (id) ON DELETE RESTRICT ON UPDATE CASCADE,',
            'REFERENCES workout_assignments (id) ON DELETE RESTRICT ON UPDATE RESTRICT,',
            $spec
        );
        self::assertNotSame($spec, $expected);
        self::assertSame($expected, $ours);
    }

    public function testSeedFileIsIdenticalToTheSpecification(): void
    {
        self::assertSame(
            hash_file('sha256', dirname(__DIR__, 3) . '/docs/spec/seed.sql'),
            hash_file('sha256', dirname(__DIR__, 2) . '/database/seed.sql')
        );
    }

    public function testTheUnmodifiedSpecSchemaIsRejectedByMariaDB(): void
    {
        // Documents WHY the deviation exists. If a future MariaDB accepts the original, this test says the deviation can go.
        $sql = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/spec/schema.sql');
        try {
            foreach (\App\Install\SqlSplitter::split($sql) as $statement) {
                $this->db->pdo()->exec($statement);
            }
        } catch (\PDOException $e) {
            self::assertSame(1901, $e->errorInfo[1], $e->getMessage());
            return;
        }
        self::markTestSkipped('This database accepts the original schema.sql: the documented deviation is no longer needed here.');
    }

    // ---- installer steps ----

    public function testSystemCheckPasses(): void
    {
        $checks = $this->installer->systemCheck(true);
        self::assertTrue(Installer::checksPass($checks), json_encode($checks));
        $names = array_column($checks, 'name');
        self::assertContains('Argon2id', $names);
        self::assertContains('MariaDB >= 10.6', $names);
    }

    public function testSystemCheckRequiresHttpsInProduction(): void
    {
        $prod = new Installer($this->config(['APP_ENV' => 'production']), $this->db, new Clock());
        $checks = $prod->systemCheck(false);
        self::assertFalse(Installer::checksPass($checks));
        self::assertTrue(Installer::checksPass($prod->systemCheck(true)));
    }

    public function testSystemCheckReportsBrokenDatabase(): void
    {
        $bad = new Installer($this->config(['DB_PASSWORD' => 'nope']), new Db($this->config(['DB_PASSWORD' => 'nope'])), new Clock());
        $checks = $bad->systemCheck(true);
        self::assertFalse(Installer::checksPass($checks));
        self::assertStringNotContainsString('nope', json_encode($checks));
    }

    public function testFullInstallThenRerunIsIdempotentAndNeverDrops(): void
    {
        $this->fullInstall();
        $users = (int) $this->db->value('SELECT COUNT(*) FROM users');
        $this->db->exec("INSERT INTO users (public_id, name, email, password, timezone) VALUES ('01ARZ3NDEKTSV4RRFFQ69G5FAV', 'Keep', 'keep@example.nl', 'x', 'UTC')");

        self::assertSame('present', $this->installer->installSchema());
        self::assertSame('present', $this->installer->seed());
        self::assertSame($users + 1, (int) $this->db->value('SELECT COUNT(*) FROM users'), 'existing data untouched');
        self::assertSame(143, (int) $this->db->value('SELECT COUNT(*) FROM workout_exercises'), 'no second seed');
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM programs'));
        self::assertTrue(Installer::allOk($this->installer->verify()), json_encode($this->installer->verify()));
    }

    public function testNonEmptyUnknownDatabaseIsRefusedAndLeftAlone(): void
    {
        $this->db->pdo()->exec('CREATE TABLE something_else (id INT)');
        $this->db->pdo()->exec('INSERT INTO something_else VALUES (1)');
        $this->expectException(InstallException::class);
        try {
            $this->installer->installSchema();
        } finally {
            self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM something_else'));
            self::assertCount(1, $this->db->all('SHOW TABLES'), 'nothing was created');
        }
    }

    public function testUnknownSchemaVersionIsRefused(): void
    {
        $this->installer->installSchema();
        $this->db->exec("UPDATE app_schema_versions SET version = '0.9.0'");
        $this->expectException(InstallException::class);
        $this->expectExceptionMessage('Onbekende schemaversie');
        $this->installer->installSchema();
    }

    public function testIncompleteSchemaIsRefused(): void
    {
        $this->installer->installSchema();
        $this->db->pdo()->exec('DROP TABLE auth_sessions');
        $this->expectException(InstallException::class);
        $this->expectExceptionMessage('auth_sessions');
        $this->installer->installSchema();
    }

    public function testConflictingContentIsNeverOverwrittenBySeed(): void
    {
        $this->installer->installSchema();
        $this->db->exec("INSERT INTO programs (public_id, code, name, version) VALUES ('01ARZ3NDEKTSV4RRFFQ69G5FAV', 'mine', 'Mine', '9')");
        try {
            $this->installer->seed();
            self::fail('seed must stop');
        } catch (InstallException $e) {
            self::assertStringContainsString('programs', $e->getMessage());
        }
        self::assertSame('mine', $this->db->value('SELECT code FROM programs'));
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM workout_templates'));
    }

    public function testUnknownDatasetVersionIsRefused(): void
    {
        $this->installer->installSchema();
        $this->installer->seed();
        $this->db->exec("UPDATE app_meta SET meta_value = '0.1.0' WHERE meta_key = 'dataset_version'");
        $this->expectException(InstallException::class);
        $this->installer->seed();
    }

    public function testAFailedSeedIsRolledBack(): void
    {
        $this->installer->installSchema();
        $broken = $this->tmp . '/db';
        mkdir($broken);
        copy(dirname(__DIR__, 2) . '/database/schema.sql', $broken . '/schema.sql');
        file_put_contents($broken . '/seed.sql', "START TRANSACTION;\nINSERT INTO app_meta (meta_key, meta_value) VALUES ('dataset_version', '1.0.0');\nINSERT INTO nope VALUES (1);\nCOMMIT;\n");
        $inst = new Installer($this->config, $this->db, new Clock(), $broken);
        try {
            $inst->seed();
            self::fail('expected failure');
        } catch (InstallException $e) {
            self::assertStringContainsString('Seed mislukt', $e->getMessage());
        } finally {
            @unlink($broken . '/schema.sql');
            @unlink($broken . '/seed.sql');
            @rmdir($broken);
        }
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM app_meta'), 'half-seeded state is rolled back');
    }

    public function testCreateUserValidation(): void
    {
        $this->installer->installSchema();
        $bad = [
            ['', 'a@example.nl', 'long-enough-password', 'UTC'],
            ['A', 'not-an-email', 'long-enough-password', 'UTC'],
            ['A', 'a@example.nl', 'short', 'UTC'],
            ['A', 'a@example.nl', 'long-enough-password', 'Mars/Base'],
            [str_repeat('x', 121), 'a@example.nl', 'long-enough-password', 'UTC'],
        ];
        foreach ($bad as $args) {
            try {
                $this->installer->createUser(...$args);
                self::fail(json_encode($args));
            } catch (InstallException) {
                self::assertTrue(true);
            }
        }
        $u = $this->installer->createUser(' Anna ', ' Anna@Example.NL ', 'long-enough-password', 'Europe/Amsterdam');
        self::assertSame('anna@example.nl', $u['email']);
        self::assertSame('Anna', $u['name']);
        self::assertStringStartsWith('$argon2id$', (string) $this->db->value('SELECT password FROM users'));
        $this->expectException(InstallException::class);
        $this->installer->createUser('Other', 'ANNA@example.nl', 'long-enough-password', 'UTC');
    }

    public function testLockFileAndKeyLifecycle(): void
    {
        self::assertFalse($this->installer->isInstalled());
        $key = $this->installer->generateKey();
        self::assertSame(48, strlen($key));
        self::assertSame('0600', substr(sprintf('%o', fileperms($this->installer->keyPath())), -4));
        self::assertTrue($this->installer->keyMatches($key));
        self::assertTrue($this->installer->keyMatches($key . "\n"));
        self::assertFalse($this->installer->keyMatches('wrong'));
        self::assertFalse($this->installer->keyMatches(''));

        $this->installer->writeLock();
        self::assertTrue($this->installer->isInstalled());
        self::assertFileDoesNotExist($this->installer->keyPath(), 'the key is single use');
        $lock = json_decode((string) file_get_contents($this->installer->lockPath()), true);
        self::assertSame(['1.0.0', '1.0.0'], [$lock['schema_version'], $lock['dataset_version']]);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $lock['installed_at']);
    }

    // ---- web wizard ----

    public function testWizardIsInvisibleWithoutKeyFile(): void
    {
        $r = $this->web()->handle(new Request('GET', '/install.php'));
        self::assertSame(404, $r->status);
        self::assertSame(404, $this->post(['key' => 'anything'])->status);
        self::assertSame(0, count($this->db->all('SHOW TABLES')), 'nothing happens without a key file');
    }

    public function testWizardIsInvisibleOnceInstalled(): void
    {
        $key = $this->installer->generateKey();
        file_put_contents($this->installer->lockPath(), '{}');
        self::assertSame(404, $this->web()->handle(new Request('GET', '/install.php'))->status);
        self::assertSame(404, $this->post(['key' => $key])->status);
        self::assertStringNotContainsString($key, (string) $this->post(['key' => $key])->body);
    }

    public function testWizardRequiresTheKeyAndRateLimitsGuesses(): void
    {
        $key = $this->installer->generateKey();
        self::assertSame(200, $this->web()->handle(new Request('GET', '/install.php'))->status);
        for ($i = 0; $i < 5; $i++) {
            $r = $this->post(['key' => 'guess' . $i]);
            self::assertSame(403, $r->status);
            self::assertStringNotContainsString($key, (string) $r->body);
        }
        self::assertSame(429, $this->post(['key' => $key])->status, 'locked out after 5 wrong keys');
        self::assertSame(200, $this->post(['key' => $key], '198.51.100.1')->status, 'other client unaffected');
        self::assertCount(0, $this->db->all('SHOW TABLES'));
    }

    public function testWizardEndToEnd(): void
    {
        $key = $this->installer->generateKey();
        $check = $this->post(['key' => $key]);
        self::assertSame(200, $check->status);
        self::assertStringContainsString('Systeemcontrole', (string) $check->body);
        self::assertStringContainsString('name="password"', (string) $check->body);
        self::assertSame(0, count($this->db->all('SHOW TABLES')), 'checking installs nothing');

        $r = $this->post([
            'step' => 'install', 'key' => $key, 'name' => 'Admin <b>', 'email' => 'admin@example.nl',
            'password' => 'a-long-enough-password', 'password_confirmation' => 'a-long-enough-password', 'timezone' => 'Europe/Amsterdam',
        ]);
        self::assertSame(200, $r->status, (string) $r->body);
        self::assertStringContainsString('Installatie voltooid', (string) $r->body);
        self::assertStringNotContainsString('a-long-enough-password', (string) $r->body);
        self::assertTrue($this->installer->isInstalled());
        self::assertFileDoesNotExist($this->installer->keyPath());
        self::assertSame('Admin <b>', $this->db->value('SELECT name FROM users'));
        self::assertSame(143, (int) $this->db->value('SELECT COUNT(*) FROM workout_exercises'));

        // closed for good, no reset function
        self::assertSame(404, $this->web()->handle(new Request('GET', '/install.php'))->status);
        self::assertSame(404, $this->post(['step' => 'install', 'key' => $key])->status);
    }

    public function testWizardRejectsMismatchedPasswordsAndEscapesReflectedInput(): void
    {
        $key = $this->installer->generateKey();
        $r = $this->post([
            'step' => 'install', 'key' => $key, 'name' => '"><script>alert(1)</script>', 'email' => 'a@example.nl',
            'password' => 'a-long-enough-password', 'password_confirmation' => 'different-password-here', 'timezone' => 'UTC',
        ]);
        self::assertSame(422, $r->status);
        self::assertStringNotContainsString('<script>alert(1)</script>', (string) $r->body);
        self::assertStringContainsString('&lt;script&gt;', (string) $r->body);
        self::assertFalse($this->installer->isInstalled());
        self::assertStringContainsString("default-src 'none'", (string) $r->headers['Content-Security-Policy']);
    }

    public function testWizardFailureLeavesTheInstallerOpenAndDatabaseUntouchedWhenRefused(): void
    {
        $key = $this->installer->generateKey();
        $this->db->pdo()->exec('CREATE TABLE foreign_table (id INT)');
        $r = $this->post([
            'step' => 'install', 'key' => $key, 'name' => 'A', 'email' => 'a@example.nl',
            'password' => 'a-long-enough-password', 'password_confirmation' => 'a-long-enough-password', 'timezone' => 'UTC',
        ]);
        self::assertSame(422, $r->status);
        self::assertStringContainsString('lege database', (string) $r->body);
        self::assertFalse($this->installer->isInstalled());
        self::assertFileExists($this->installer->keyPath());
    }

    public function testWizardNeedsHttpsInProduction(): void
    {
        $inst = new Installer($this->config(['APP_ENV' => 'production']), $this->db, new Clock());
        $key = $inst->generateKey();
        $web = new WebInstaller($inst, $this->config(['APP_ENV' => 'production']), new RateLimiter($this->tmp . '/ratelimit', new Logger(null, 'error')), new Logger(null, 'error'));
        $r = $web->handle(new Request('POST', '/install.php', [], [], [], http_build_query([
            'step' => 'install', 'key' => $key, 'name' => 'A', 'email' => 'a@example.nl',
            'password' => 'a-long-enough-password', 'password_confirmation' => 'a-long-enough-password', 'timezone' => 'UTC',
        ]), '203.0.113.3'));
        self::assertSame(422, $r->status);
        self::assertStringContainsString('HTTPS', (string) $r->body);
        self::assertCount(0, $this->db->all('SHOW TABLES'));
    }

    // ---- CLI ----

    /** @param array<string,string> $env @return array{int,string,string} */
    private function cli(array $args, array $env = [], ?string $stdin = null): array
    {
        $cmd = array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/bin/install.php'], $args);
        $envv = $env + Env::dbSettings(self::DB) + [
            'APP_ENV' => 'testing', 'INSTALL_KEY_PATH' => $this->tmp . '/install.key', 'INSTALL_LOCK_PATH' => $this->tmp . '/installed.lock',
            'LOG_PATH' => $this->tmp . '/app.log', 'ENV_FILE' => '/dev/null', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        ];
        $envv['DB_DATABASE'] = self::DB;
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $envv);
        fwrite($pipes[0], $stdin ?? '');
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        return [proc_close($p), (string) $out, (string) $err];
    }

    public function testCliInstallAndLock(): void
    {
        [$code, $out, $err] = $this->cli(['--name=CLI Admin', '--email=cli@example.nl', '--timezone=Europe/Amsterdam', '--password-stdin'], [], "a-long-enough-password\n");
        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString('Installed.', $out);
        self::assertStringNotContainsString('a-long-enough-password', $out . $err);
        self::assertSame('cli@example.nl', $this->db->value('SELECT email FROM users'));
        self::assertFileExists($this->tmp . '/installed.lock');

        [$code2, , $err2] = $this->cli(['--name=X', '--email=x@example.nl', '--password-stdin'], [], "another-long-password\n");
        self::assertSame(1, $code2);
        self::assertStringContainsString('Already installed', $err2);
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM users'));
    }

    public function testCliPasswordFromEnvironmentAndMissingPassword(): void
    {
        [$code, , $err] = $this->cli(['--name=A', '--email=a@example.nl']);
        self::assertSame(2, $code);
        self::assertStringContainsString('No password', $err);
        self::assertCount(0, $this->db->all('SHOW TABLES'));

        [$code, $out, $err] = $this->cli(['--name=A', '--email=a@example.nl'], ['INSTALL_ADMIN_PASSWORD' => 'a-long-enough-password']);
        self::assertSame(0, $code, $out . $err);
    }

    public function testCliRefusesWeakPasswordWithoutLocking(): void
    {
        [$code, , $err] = $this->cli(['--name=A', '--email=a@example.nl', '--password-stdin'], [], "short\n");
        self::assertSame(1, $code);
        self::assertStringContainsString('minimaal', $err);
        self::assertFileDoesNotExist($this->tmp . '/installed.lock');
    }

    public function testCliGenerateKey(): void
    {
        [$code, $out] = $this->cli(['--generate-key']);
        self::assertSame(0, $code);
        self::assertFileExists($this->tmp . '/install.key');
        self::assertStringContainsString(trim((string) file_get_contents($this->tmp . '/install.key')), $out);
    }

    public function testCreateUserCli(): void
    {
        $this->fullInstall();
        $cmd = [PHP_BINARY, dirname(__DIR__, 2) . '/bin/create-user.php', '--name=Second', '--email=second@example.nl', '--password-stdin'];
        $env = Env::dbSettings(self::DB) + ['APP_ENV' => 'testing', 'ENV_FILE' => '/dev/null', 'LOG_PATH' => $this->tmp . '/app.log', 'PATH' => getenv('PATH') ?: '/usr/bin'];
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        fwrite($pipes[0], "another-long-password\n");
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($p));
        self::assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM users'));
    }

    public function testHealthEndpointAfterInstall(): void
    {
        $this->fullInstall();
        $app = new Application($this->config);
        $res = $app->kernel()->handle(new Request('GET', '/health'));
        self::assertSame(200, $res->status);
        self::assertSame('{"status":"ok","schema_version":"1.0.0","dataset_version":"1.0.0"}', $res->body);
    }

    public function testHealthIsNotOkOnAnEmptyDatabase(): void
    {
        $app = new Application($this->config);
        $res = $app->kernel()->handle(new Request('GET', '/health'));
        self::assertSame(503, $res->status);
        self::assertSame('{"status":"error"}', $res->body);
    }
}
