#!/usr/bin/env php
<?php
declare(strict_types=1);

// Daily housekeeping: expired auth_sessions and stale rate-limit files (INSTALLATION_REQUIREMENTS section 13).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$sessions = $app->sessions()->purgeExpired();
$files = $app->rateLimiter()->purge();
fwrite(STDOUT, "expired sessions removed: $sessions, rate-limit files removed: $files\n");
