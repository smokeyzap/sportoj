<?php
declare(strict_types=1);

use App\Install\Installer;
use App\Support\Clock;
use App\Support\Config;
use App\Support\Db;
use Tests\Support\Env;

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');

// Build the test database once per run from the real installer path (this also exercises AT-001/AT-002).
$base = dirname(__DIR__);
$cfg = new Config(Env::dbSettings(), $base);
$name = (string) $cfg->get('DB_DATABASE');
if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1 || !str_contains($name, 'test')) {
    fwrite(STDERR, "Refusing to drop database '$name': the TEST_DB_DATABASE name must contain 'test'.\n");
    exit(1);
}
Env::recreateDatabase($name);
$installer = new Installer($cfg, new Db($cfg), new Clock());
$installer->installSchema();
$installer->seed();
