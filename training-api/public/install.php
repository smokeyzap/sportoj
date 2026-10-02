<?php
declare(strict_types=1);

use App\Http\Request;
use App\Install\Installer;
use App\Install\WebInstaller;

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$installer = new Installer($app->config, $app->db(), $app->clock());
(new WebInstaller($installer, $app->config, $app->rateLimiter(), $app->logger()))->handle(Request::fromGlobals())->send();
