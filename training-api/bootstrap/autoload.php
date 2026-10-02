<?php
declare(strict_types=1);

// Production may ship without vendor/ (INSTALLATION_REQUIREMENTS section 2): the runtime has no third-party dependencies.
$vendor = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendor)) {
    require_once $vendor;
    return;
}
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = dirname(__DIR__) . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
