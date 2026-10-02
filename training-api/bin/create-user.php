#!/usr/bin/env php
<?php
declare(strict_types=1);

// Adds a further user after installation. Usage: php bin/create-user.php --name="..." --email=... [--timezone=...] [--password-stdin]
// Password: INSTALL_ADMIN_PASSWORD env, stdin or prompt (never argv).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use App\Install\InstallException;
use App\Install\Installer;

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$installer = new Installer($app->config, $app->db(), $app->clock());
$opts = getopt('', ['name:', 'email:', 'timezone:', 'password-stdin']);
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
try {
    $user = $installer->createUser((string) ($opts['name'] ?? ''), (string) ($opts['email'] ?? ''), $password, (string) ($opts['timezone'] ?? 'Europe/Amsterdam'));
    fwrite(STDOUT, 'created ' . $user['email'] . ' (' . $user['id'] . ")\n");
} catch (InstallException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
