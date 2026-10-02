<?php
declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;

try {
    $app = require dirname(__DIR__) . '/bootstrap/app.php';
    $app->kernel()->handle(Request::fromGlobals())->send();
} catch (Throwable $e) {
    // Boot failure (e.g. invalid configuration): never leak details.
    error_log('boot failure: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo '{"error":{"code":"INTERNAL_ERROR","message":"Er is een onverwachte fout opgetreden."}}';
}
