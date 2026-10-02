<?php
declare(strict_types=1);

use App\Application;
use App\Support\Config;

require_once __DIR__ . '/autoload.php';

$config = Config::fromEnvFile(dirname(__DIR__));
if (!$config->debug()) {
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');

return new Application($config);
