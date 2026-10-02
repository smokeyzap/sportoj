<?php
declare(strict_types=1);

// Router script for `php -S`: mimics the Apache/nginx rule "existing file -> serve, otherwise front controller".
$public = dirname(__DIR__, 2) . '/public';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path !== '/' && is_file($public . $path) && !str_ends_with((string) $path, '.php')) {
    return false;
}
if (is_file($public . $path) && str_ends_with((string) $path, '.php') && $path !== '/index.php') {
    require $public . $path;
    return true;
}
require $public . '/index.php';
return true;
