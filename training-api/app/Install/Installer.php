<?php
declare(strict_types=1);

namespace App\Install;

use App\Auth\PasswordHasher;
use App\Support\Clock;
use App\Support\Config;
use App\Support\Db;
use App\Support\Ulid;

/**
 * Installation steps from INSTALLATION_REQUIREMENTS section 12. Never drops or resets anything; stops with a clear
 * message when the database is not in an expected state.
 */
final class Installer
{
    public const SCHEMA_VERSION = '1.0.0';
    public const DATASET_VERSION = '1.0.0';

    private const TABLES = [
        'app_schema_versions', 'app_meta', 'users', 'auth_sessions', 'programs', 'training_blocks', 'workout_templates',
        'workout_exercises', 'block_workouts', 'user_programs', 'user_program_blocks', 'workout_assignments', 'workout_sessions',
    ];
    private const CONTENT_TABLES = ['programs', 'training_blocks', 'workout_templates', 'workout_exercises', 'block_workouts'];

    public function __construct(private Config $config, private Db $db, private Clock $clock, private ?string $databaseDir = null)
    {
        $this->databaseDir ??= $config->basePath . '/database';
    }

    public function lockPath(): string
    {
        return $this->config->path('INSTALL_LOCK_PATH', 'storage/installed.lock');
    }

    public function keyPath(): string
    {
        return $this->config->path('INSTALL_KEY_PATH', 'storage/install.key');
    }

    public function isInstalled(): bool
    {
        return is_file($this->lockPath());
    }

    /** Create storage/install.key (mode 0600) and return the key. */
    public function generateKey(): string
    {
        $key = bin2hex(random_bytes(24));
        $path = $this->keyPath();
        if (@file_put_contents($path, $key . "\n") === false) {
            throw new InstallException('Kan installatiesleutel niet schrijven naar ' . $path);
        }
        @chmod($path, 0600);
        return $key;
    }

    public function keyMatches(string $presented): bool
    {
        $stored = @file_get_contents($this->keyPath());
        return $stored !== false && trim($stored) !== '' && $presented !== '' && hash_equals(trim($stored), trim($presented));
    }

    /** @return list<array{name:string,ok:bool,required:bool,detail:string}> */
    public function systemCheck(bool $https): array
    {
        $checks = [];
        $add = static function (string $name, bool $ok, string $detail, bool $required = true) use (&$checks): void {
            $checks[] = ['name' => $name, 'ok' => $ok, 'required' => $required, 'detail' => $detail];
        };
        $add('PHP >= 8.2', PHP_VERSION_ID >= 80200, PHP_VERSION);
        foreach (['pdo', 'pdo_mysql', 'json', 'mbstring', 'openssl', 'filter'] as $ext) {
            $add("Extensie $ext", extension_loaded($ext), extension_loaded($ext) ? 'aanwezig' : 'ontbreekt');
        }
        $argon = PasswordHasher::algorithm() === PASSWORD_ARGON2ID;
        $add('Argon2id', $argon, $argon ? 'beschikbaar (PASSWORD_ARGON2ID)' : 'niet beschikbaar: bcrypt-fallback wordt gebruikt', false);
        foreach (['storage', 'storage/logs', 'storage/ratelimit'] as $dir) {
            $path = $this->config->basePath . '/' . $dir;
            $add("Schrijfbaar: $dir", is_dir($path) && is_writable($path), $path);
        }
        $add('HTTPS', $https || $this->config->env() !== 'production', $https ? 'ja' : 'nee (verplicht in productie)');
        try {
            $version = (string) $this->db->value('SELECT VERSION()');
            $charset = (string) $this->db->value('SELECT @@character_set_database');
            $isMaria = stripos($version, 'mariadb') !== false;
            $clean = preg_replace('/^5\.5\.5-/', '', $version) ?? $version;   // some drivers prefix MariaDB with the MySQL compat version
            $num = preg_match('/(\d+\.\d+\.\d+)/', $clean, $m) === 1 ? $m[1] : '0.0.0';
            $add('Databaseverbinding', true, $version . ', charset ' . $charset);
            $add('MariaDB >= 10.6', !$isMaria || version_compare($num, '10.6.0', '>='), $version);
            $add('Database charset utf8mb4', $charset === 'utf8mb4', $charset, false);
        } catch (\Throwable $e) {
            $add('Databaseverbinding', false, 'mislukt: ' . $e->getMessage());
        }
        return $checks;
    }

    /** @param list<array{ok:bool,required:bool}> $checks */
    public static function checksPass(array $checks): bool
    {
        foreach ($checks as $c) {
            if ($c['required'] && !$c['ok']) {
                return false;
            }
        }
        return true;
    }

    /** @return string 'created' | 'present' */
    public function installSchema(): string
    {
        $tables = $this->existingTables();
        if (!in_array('app_schema_versions', $tables, true)) {
            if ($tables !== []) {
                throw new InstallException('De database bevat al tabellen (' . implode(', ', array_slice($tables, 0, 5)) . ') maar geen app_schema_versions. Gebruik een lege database; er wordt niets overschreven.');
            }
            $this->runScript($this->databaseDir . '/schema.sql');
            return 'created';
        }
        $versions = array_column($this->db->all('SELECT version FROM app_schema_versions'), 'version');
        if (!in_array(self::SCHEMA_VERSION, $versions, true)) {
            throw new InstallException('Onbekende schemaversie in database: ' . implode(', ', $versions) . '. Verwacht ' . self::SCHEMA_VERSION . '.');
        }
        $missing = array_diff(self::TABLES, $tables);
        if ($missing !== []) {
            throw new InstallException('Schema ' . self::SCHEMA_VERSION . ' is aanwezig maar onvolledig; tabellen ontbreken: ' . implode(', ', $missing));
        }
        return 'present';
    }

    /** @return string 'seeded' | 'present' */
    public function seed(): string
    {
        $dataset = $this->db->value("SELECT meta_value FROM app_meta WHERE meta_key = 'dataset_version'");
        if ($dataset !== null) {
            if ($dataset !== self::DATASET_VERSION) {
                throw new InstallException("Onbekende datasetversie '$dataset'; verwacht " . self::DATASET_VERSION . '.');
            }
            return 'present';
        }
        foreach (self::CONTENT_TABLES as $t) {
            if ((int) $this->db->value("SELECT COUNT(*) FROM `$t`") > 0) {
                throw new InstallException("Tabel $t bevat al data zonder dataset_version; seed wordt niet uitgevoerd om conflicten te voorkomen.");
            }
        }
        try {
            $this->runScript($this->databaseDir . '/seed.sql');
        } catch (\Throwable $e) {
            try {
                $this->db->pdo()->exec('ROLLBACK');
            } catch (\Throwable) {
            }
            throw new InstallException('Seed mislukt: ' . $e->getMessage(), 0, $e);
        }
        return 'seeded';
    }

    /** @return array<string,mixed> the created user (public id) */
    public function createUser(string $name, string $email, string $password, string $timezone): array
    {
        $name = trim($name);
        $email = mb_strtolower(trim($email));
        $min = max(8, $this->config->int('PASSWORD_MIN_LENGTH', 12));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InstallException('Naam is verplicht (maximaal 120 tekens).');
        }
        if (mb_strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InstallException('Ongeldig e-mailadres.');
        }
        if (mb_strlen($password) < $min) {
            throw new InstallException("Wachtwoord moet minimaal $min tekens bevatten.");
        }
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new InstallException('Ongeldige timezone.');
        }
        if ($this->db->value('SELECT 1 FROM users WHERE email = ?', [$email]) !== null) {
            throw new InstallException('Er bestaat al een gebruiker met dit e-mailadres.');
        }
        $publicId = Ulid::generate();
        $this->db->exec(
            'INSERT INTO users (public_id, name, email, password, timezone) VALUES (?, ?, ?, ?, ?)',
            [$publicId, $name, $email, (new PasswordHasher())->hash($password), $timezone]
        );
        return ['id' => $publicId, 'name' => $name, 'email' => $email, 'timezone' => $timezone];
    }

    /** @return list<array{name:string,ok:bool,detail:string}> */
    public function verify(): array
    {
        $out = [];
        $add = static function (string $n, bool $ok, string $d) use (&$out): void {
            $out[] = ['name' => $n, 'ok' => $ok, 'detail' => $d];
        };
        $count = fn (string $sql): int => (int) $this->db->value($sql);
        $schema = (string) $this->db->value('SELECT version FROM app_schema_versions ORDER BY applied_at DESC, version DESC LIMIT 1');
        $dataset = (string) $this->db->value("SELECT meta_value FROM app_meta WHERE meta_key = 'dataset_version'");
        $add('schema_version', $schema === self::SCHEMA_VERSION, $schema);
        $add('dataset_version', $dataset === self::DATASET_VERSION, $dataset);
        foreach (['training_blocks' => 5, 'workout_templates' => 30, 'workout_exercises' => 143] as $t => $expected) {
            $n = $count("SELECT COUNT(*) FROM $t");
            $add($t, $n === $expected, "$n (verwacht $expected)");
        }
        $users = $count('SELECT COUNT(*) FROM users');
        $add('users', $users >= 1, (string) $users);
        return $out;
    }

    /** @param list<array{ok:bool}> $results */
    public static function allOk(array $results): bool
    {
        foreach ($results as $r) {
            if (!$r['ok']) {
                return false;
            }
        }
        return true;
    }

    public function writeLock(): void
    {
        $payload = json_encode([
            'installed_at' => $this->clock->now()->format('Y-m-d\TH:i:s\Z'),
            'schema_version' => self::SCHEMA_VERSION,
            'dataset_version' => self::DATASET_VERSION,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($this->lockPath(), $payload . "\n", LOCK_EX) === false) {
            throw new InstallException('Kan ' . $this->lockPath() . ' niet schrijven; de installer blijft open. Maak het bestand handmatig aan.');
        }
        @unlink($this->keyPath());   // the key is single use
    }

    /** @return list<string> */
    private function existingTables(): array
    {
        return array_map(static fn (array $r): string => (string) array_values($r)[0], $this->db->all('SHOW TABLES'));
    }

    private function runScript(string $file): void
    {
        $sql = @file_get_contents($file);
        if ($sql === false) {
            throw new InstallException('Kan SQL-bestand niet lezen: ' . $file);
        }
        $pdo = $this->db->pdo();
        foreach (SqlSplitter::split($sql) as $statement) {
            $pdo->exec($statement);
        }
    }
}
