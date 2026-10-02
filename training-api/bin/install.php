#!/usr/bin/env php
<?php
declare(strict_types=1);

// CLI installer. Usage:
//   php bin/install.php --generate-key                      create storage/install.key (needed for public/install.php)
//   php bin/install.php --name="..." --email=... [--timezone=Europe/Amsterdam] [--password-stdin]
// The password is read from INSTALL_ADMIN_PASSWORD, stdin (--password-stdin) or an interactive prompt. Never from argv.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use App\Install\InstallException;
use App\Install\Installer;

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$installer = new Installer($app->config, $app->db(), $app->clock());
$opts = getopt('', ['generate-key', 'name:', 'email:', 'timezone:', 'password-stdin', 'help']);

if (isset($opts['help'])) {
    fwrite(STDOUT, "See header of bin/install.php or README.md\n");
    exit(0);
}
if ($installer->isInstalled()) {
    fwrite(STDERR, "Already installed (" . $installer->lockPath() . "). The installer has no reset function.\n");
    exit(1);
}
if (isset($opts['generate-key'])) {
    try {
        $key = $installer->generateKey();
    } catch (InstallException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    fwrite(STDOUT, "Installation key written to " . $installer->keyPath() . " (mode 0600):\n$key\n");
    exit(0);
}

$name = (string) ($opts['name'] ?? '');
$email = (string) ($opts['email'] ?? '');
$tz = (string) ($opts['timezone'] ?? 'Europe/Amsterdam');
if ($name === '' || $email === '') {
    fwrite(STDERR, "--name and --email are required.\n");
    exit(2);
}
$password = getenv('INSTALL_ADMIN_PASSWORD') ?: '';
if ($password === '' && isset($opts['password-stdin'])) {
    $password = rtrim((string) fgets(STDIN), "\r\n");
}
if ($password === '' && function_exists('posix_isatty') && posix_isatty(STDIN)) {
    fwrite(STDOUT, 'Password: ');
    @shell_exec('stty -echo');
    $password = rtrim((string) fgets(STDIN), "\r\n");
    @shell_exec('stty echo');
    fwrite(STDOUT, "\n");
}
if ($password === '') {
    fwrite(STDERR, "No password supplied (INSTALL_ADMIN_PASSWORD, --password-stdin or interactive prompt).\n");
    exit(2);
}

try {
    $checks = $installer->systemCheck(true);
    foreach ($checks as $c) {
        fwrite(STDOUT, sprintf("[%s] %s: %s\n", $c['ok'] ? 'ok' : ($c['required'] ? 'FAIL' : 'warn'), $c['name'], $c['detail']));
    }
    if (!Installer::checksPass($checks)) {
        fwrite(STDERR, "System check failed.\n");
        exit(1);
    }
    fwrite(STDOUT, 'schema: ' . $installer->installSchema() . "\n");
    fwrite(STDOUT, 'dataset: ' . $installer->seed() . "\n");
    $user = $installer->createUser($name, $email, $password, $tz);
    fwrite(STDOUT, 'user: ' . $user['email'] . ' (' . $user['id'] . ")\n");
    $verify = $installer->verify();
    foreach ($verify as $v) {
        fwrite(STDOUT, sprintf("[%s] %s: %s\n", $v['ok'] ? 'ok' : 'FAIL', $v['name'], $v['detail']));
    }
    if (!Installer::allOk($verify)) {
        fwrite(STDERR, "Verification failed; installer left open.\n");
        exit(1);
    }
    $installer->writeLock();
    fwrite(STDOUT, "Installed. Lock written to " . $installer->lockPath() . ".\n");
} catch (InstallException $e) {
    fwrite(STDERR, 'Install failed: ' . $e->getMessage() . "\n");
    exit(1);
}
