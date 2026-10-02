<?php
declare(strict_types=1);

namespace Tests\Acceptance;

use Tests\Support\ApiTestCase;
use Tests\Support\Env;

/** AT-150..AT-152 and BACKUP_RESTORE.md sections 5-8. Runs the shipped shell scripts against real data. */
final class BackupRestoreTest extends ApiTestCase
{
    private const RESTORE_DB = 'training_test_restore';
    private string $backups;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['mariadb-dump', 'mariadb'] as $bin) {
            if (trim((string) shell_exec("command -v $bin")) === '' && trim((string) shell_exec('command -v ' . ($bin === 'mariadb' ? 'mysql' : 'mysqldump'))) === '') {
                self::markTestSkipped("$bin / mysql client not installed");
            }
        }
        if (trim((string) shell_exec('command -v gzip sha256sum')) === '') {
            self::markTestSkipped('gzip/sha256sum missing');
        }
        $this->backups = $this->tmp . '/backups';
        Env::recreateDatabase(self::RESTORE_DB);
    }

    /** @param array<string,string> $env @return array{int,string} */
    private function script(string $name, array $args = [], array $env = []): array
    {
        $s = Env::dbSettings();
        $envv = $env + [
            'DB_HOST' => $s['DB_HOST'], 'DB_PORT' => $s['DB_PORT'], 'DB_USERNAME' => $s['DB_USERNAME'], 'DB_PASSWORD' => $s['DB_PASSWORD'],
            'DB_DATABASE' => $s['DB_DATABASE'], 'BACKUP_DIR' => $this->backups, 'ENV_FILE' => '/dev/null', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'BACKUP_MIN_BYTES' => '2048', 'HOME' => $this->tmp,
        ];
        $cmd = array_merge(['bash', dirname(__DIR__, 2) . '/scripts/' . $name], $args);
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $envv);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        return [proc_close($p), $out];
    }

    private function seedRealisticData(): void
    {
        $c = $this->loggedIn();
        $this->startProgram($c);
        $this->complete($c, $this->aid($c, 1, 1, 1), 'backup me');
        $this->loggedIn('b@example.nl', 'User B');
    }

    /** @return list<string> */
    private function files(string $tier): array
    {
        $f = glob($this->backups . "/$tier/trainingsapp_*.sql.gz") ?: [];
        sort($f);
        return $f;
    }

    public function testAT150BackupProducesAValidFile(): void
    {
        $this->seedRealisticData();
        [$code, $out] = $this->script('backup_database.sh', [], ['BACKUP_NOW' => '20260817T210000Z']);
        self::assertSame(0, $code, $out);

        $file = $this->backups . '/daily/trainingsapp_20260817T210000Z_schema-1.0.0.sql.gz';
        self::assertFileExists($file);
        self::assertFileExists($file . '.sha256');
        self::assertGreaterThan(2048, filesize($file));
        self::assertSame(0, $this->exec("gzip -t " . escapeshellarg($file)));
        self::assertSame(0, $this->exec('cd ' . escapeshellarg(dirname($file)) . ' && sha256sum -c ' . escapeshellarg(basename($file) . '.sha256')));

        $sql = (string) shell_exec('gunzip -c ' . escapeshellarg($file));
        self::assertStringContainsString('CREATE TABLE `workout_sessions`', $sql);
        self::assertStringContainsString('INSERT INTO `workout_templates`', $sql);
        self::assertStringContainsString('backup me', $sql);
        self::assertStringNotContainsStringIgnoringCase('password', $out, 'credentials are never printed');
        self::assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
        self::assertSame([], glob($this->backups . '/.work.*') ?: [], 'temp work dir is cleaned up');
    }

    public function testAT151And152RestoreTestIntoAnEmptyTemporaryDatabase(): void
    {
        $this->seedRealisticData();
        $this->script('backup_database.sh', [], ['BACKUP_NOW' => '20260817T210000Z']);
        $file = $this->files('daily')[0];

        [$code, $out] = $this->script('restore_verify.sh', [$file], ['RESTORE_DB_DATABASE' => self::RESTORE_DB, 'EXPECT_MIN_USERS' => '2']);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('RESTORE_OK schema=1.0.0 dataset=1.0.0 programs=1 blocks=5 templates=30 users=2 user_programs=1 workout_sessions=1', $out);

        // AT-152: the restored copy has the real content
        $r = new \App\Support\Db(new \App\Support\Config(Env::dbSettings(self::RESTORE_DB), dirname(__DIR__, 2)));
        self::assertSame(143, (int) $r->value('SELECT COUNT(*) FROM workout_exercises'));
        self::assertSame(84, (int) $r->value('SELECT COUNT(*) FROM workout_assignments'));
        self::assertSame('backup me', $r->value('SELECT notes FROM workout_sessions'));
        self::assertSame('completed', $r->value("SELECT status FROM workout_assignments WHERE completed_at IS NOT NULL"));
        self::assertSame(
            $this->db->all('SELECT public_id, email FROM users ORDER BY id'),
            $r->all('SELECT public_id, email FROM users ORDER BY id')
        );
        self::assertStringContainsString('OK schema=1.0.0', (string) file_get_contents($this->backups . '/restore-tests.log'), 'result is logged (section 9)');
    }

    public function testRestoreRefusesANonEmptyDatabase(): void
    {
        $this->seedRealisticData();
        $this->script('backup_database.sh', [], ['BACKUP_NOW' => '20260817T210000Z']);
        $file = $this->files('daily')[0];
        $this->script('restore_verify.sh', [$file], ['RESTORE_DB_DATABASE' => self::RESTORE_DB]);
        [$code, $out] = $this->script('restore_verify.sh', [$file], ['RESTORE_DB_DATABASE' => self::RESTORE_DB]);
        self::assertSame(2, $code);
        self::assertStringContainsString('not empty', $out);
    }

    public function testRestoreRefusesToTouchProduction(): void
    {
        $this->seedRealisticData();
        $this->script('backup_database.sh', [], ['BACKUP_NOW' => '20260817T210000Z']);
        $file = $this->files('daily')[0];
        $before = (int) $this->db->value('SELECT COUNT(*) FROM users');
        [$code, $out] = $this->script('restore_verify.sh', [$file], ['RESTORE_DB_DATABASE' => Env::dbSettings()['DB_DATABASE']]);
        self::assertSame(2, $code);
        self::assertStringContainsString('production', $out);
        self::assertSame($before, (int) $this->db->value('SELECT COUNT(*) FROM users'));

        [$code, $out] = $this->script('restore_verify.sh', [$file], ['RESTORE_DB_DATABASE' => 'trainingsapp']);
        self::assertSame(2, $code, 'a name that is not obviously a test database needs an explicit override');
        self::assertStringContainsString("'restore' or 'test'", $out);
    }

    public function testRestoreDetectsCorruptionAndMissingChecksum(): void
    {
        $this->seedRealisticData();
        $this->script('backup_database.sh', [], ['BACKUP_NOW' => '20260817T210000Z']);
        $file = $this->files('daily')[0];

        $bad = $this->tmp . '/bad';
        mkdir($bad);
        $corrupt = "$bad/" . basename($file);
        $data = (string) file_get_contents($file);
        file_put_contents($corrupt, substr($data, 0, (int) (strlen($data) / 2)));          // truncated gzip
        copy($file . '.sha256', $corrupt . '.sha256');
        [$code, $out] = $this->script('restore_verify.sh', [$corrupt], ['RESTORE_DB_DATABASE' => self::RESTORE_DB]);
        self::assertSame(1, $code);
        self::assertStringContainsString('gzip integrity', $out);

        copy($file, "$bad/ok.sql.gz");
        [$code, $out] = $this->script('restore_verify.sh', ["$bad/ok.sql.gz"], ['RESTORE_DB_DATABASE' => self::RESTORE_DB]);
        self::assertSame(1, $code);
        self::assertStringContainsString('checksum file missing', $out);

        file_put_contents($file . '.sha256', str_repeat('0', 64) . '  ' . basename($file) . "\n");
        [$code, $out] = $this->script('restore_verify.sh', [$file], ['RESTORE_DB_DATABASE' => self::RESTORE_DB]);
        self::assertSame(1, $code);
        self::assertStringContainsString('checksum does not match', $out);
        self::assertSame(0, count((new \App\Support\Db(new \App\Support\Config(Env::dbSettings(self::RESTORE_DB), dirname(__DIR__, 2))))->all('SHOW TABLES')), 'nothing imported from a bad backup');
        foreach (glob("$bad/*") as $f) {
            @unlink($f);
        }
        @rmdir($bad);
    }

    public function testRetentionKeeps14DailyAnd8WeeklyAnd6Monthly(): void
    {
        $this->seedRealisticData();
        // 130 consecutive days, ending Wednesday 2026-12-30
        $start = new \DateTimeImmutable('2026-08-23', new \DateTimeZone('UTC'));   // a Sunday
        for ($i = 0; $i < 130; $i++) {
            $d = $start->modify("+$i days");
            [$code, $out] = $this->script('backup_database.sh', [], ['BACKUP_NOW' => $d->format('Ymd') . 'T030000Z', 'BACKUP_MIN_BYTES' => '100']);
            self::assertSame(0, $code, $out);
        }
        $daily = array_map('basename', $this->files('daily'));
        self::assertCount(14, $daily);
        self::assertStringContainsString('20261228T', $daily[11]);
        self::assertStringContainsString('20261230T', end($daily), 'newest kept');
        self::assertStringContainsString('20261217T', $daily[0], 'oldest of the 14');

        $weekly = array_map('basename', $this->files('weekly'));
        self::assertCount(8, $weekly, 'only Sundays, newest 8');
        foreach ($weekly as $w) {
            preg_match('/_(\d{8})T/', $w, $m);
            self::assertSame('7', (new \DateTimeImmutable($m[1]))->format('N'), $w);
        }
        self::assertStringContainsString('20261108T', $weekly[0]);
        self::assertStringContainsString('20261227T', $weekly[7]);

        // first-of-month snapshots inside the range: 1 Sep, 1 Oct, 1 Nov, 1 Dec (fewer than the 6 kept)
        $monthly = array_map('basename', $this->files('monthly'));
        self::assertCount(4, $monthly);
        foreach (['20260901T', '20261001T', '20261101T', '20261201T'] as $i => $prefix) {
            self::assertStringContainsString($prefix, $monthly[$i]);
        }
    }

    private function exec(string $cmd): int
    {
        exec($cmd . ' 2>&1', $o, $rc);
        return $rc;
    }

    public function testRetentionIsConfigurable(): void
    {
        $this->seedRealisticData();
        foreach (['20260801', '20260802', '20260803', '20260804', '20260805'] as $d) {
            $this->script('backup_database.sh', [], ['BACKUP_NOW' => $d . 'T030000Z', 'BACKUP_KEEP_DAILY' => '2', 'BACKUP_KEEP_MONTHLY' => '1', 'BACKUP_MIN_BYTES' => '100']);
        }
        self::assertCount(2, $this->files('daily'));
        self::assertCount(1, $this->files('monthly'), '1 August is a monthly snapshot');
        self::assertCount(1, $this->files('weekly'), '2 August 2026 is a Sunday');
        // checksum files of pruned backups are removed too
        self::assertCount(2, glob($this->backups . '/daily/*.sha256'));
    }

    public function testBackupFailsLoudlyOnBadInput(): void
    {
        [$code, $out] = $this->script('backup_database.sh', [], ['DB_PASSWORD' => 'wrong']);
        self::assertNotSame(0, $code);
        self::assertSame([], $this->files('daily'), 'no (partial) backup file is left behind');
        self::assertStringNotContainsString('wrong', $out);

        [$code] = $this->script('backup_database.sh', [], ['BACKUP_DIR' => 'relative/dir']);
        self::assertNotSame(0, $code);
        [$code, $out] = $this->script('backup_database.sh', [], ['BACKUP_DIR' => dirname(__DIR__, 2) . '/public/backups']);
        self::assertNotSame(0, $code);
        self::assertStringContainsString('outside the public webroot', $out);
        [$code, $out] = $this->script('backup_database.sh', [], ['BACKUP_NOW' => 'yesterday']);
        self::assertNotSame(0, $code);
    }

    public function testTooSmallBackupIsRejected(): void
    {
        $this->seedRealisticData();
        [$code, $out] = $this->script('backup_database.sh', [], ['BACKUP_NOW' => '20260817T210000Z', 'BACKUP_MIN_BYTES' => '99999999']);
        self::assertNotSame(0, $code);
        self::assertStringContainsString('refusing to keep', $out);
        self::assertSame([], $this->files('daily'));
    }

    public function testRemoteCopyHookRunsPerTierAndFailureKeepsLocalRestorePoints(): void
    {
        $this->seedRealisticData();
        $remote = $this->tmp . '/remote';
        mkdir($remote);
        // 2 August 2026 = Sunday -> daily + weekly; 1 August = Saturday and first of month -> daily + monthly
        $this->script('backup_database.sh', [], ['BACKUP_NOW' => '20260802T030000Z', 'BACKUP_REMOTE_COMMAND' => 'cp "$BACKUP_FILE" ' . escapeshellarg($remote) . '/"$BACKUP_TIER"_$(basename "$BACKUP_FILE")']);
        self::assertCount(2, glob($remote . '/*'), 'daily + weekly were copied');
        self::assertNotEmpty(glob($remote . '/weekly_*'));

        $alert = $this->tmp . '/alert.txt';
        [$code, $out] = $this->script('backup_database.sh', [], [
            'BACKUP_NOW' => '20260803T030000Z', 'BACKUP_KEEP_DAILY' => '1',
            'BACKUP_REMOTE_COMMAND' => 'exit 7', 'BACKUP_ALERT_COMMAND' => 'printf "%s" "$BACKUP_ALERT_MESSAGE" > ' . escapeshellarg($alert),
        ]);
        self::assertSame(3, $code);
        self::assertCount(2, $this->files('daily'), 'retention not applied while the external copy is failing');
        self::assertFileExists($alert);
        self::assertStringContainsString('external copy failed', (string) file_get_contents($alert));
        self::assertStringContainsString('remote copy failed', $out);
    }

    public function testBackupContainsDataWrittenThroughTheApiBeforeTheDump(): void
    {
        $this->seedRealisticData();
        $c = new \Tests\Support\TestClient($this->app);
        $c->login('a@example.nl', self::PASSWORD);
        $this->complete($c, $this->aid($c, 1, 1, 2), 'laatste notitie');
        [$code, $out] = $this->script('backup_database.sh', [], ['BACKUP_NOW' => '20260817T210000Z']);
        self::assertSame(0, $code, $out);
        $sql = (string) shell_exec('gunzip -c ' . escapeshellarg($this->files('daily')[0]));
        self::assertStringContainsString('laatste notitie', $sql);
    }
}
