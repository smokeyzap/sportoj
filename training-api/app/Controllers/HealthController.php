<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Application;
use App\Http\Response;

final class HealthController
{
    public function __construct(private Application $app)
    {
    }

    public function show(): Response
    {
        try {
            $db = $this->app->db();
            $schema = $db->value('SELECT version FROM app_schema_versions ORDER BY applied_at DESC, version DESC LIMIT 1');
            $dataset = $db->value("SELECT meta_value FROM app_meta WHERE meta_key = 'dataset_version'");
            if ($schema === null || $dataset === null) {
                throw new \RuntimeException('schema or dataset not installed');
            }
            return Response::json(200, ['status' => 'ok', 'schema_version' => (string) $schema, 'dataset_version' => (string) $dataset]);
        } catch (\Throwable $e) {
            $this->app->logger()->error('health check failed', ['error' => $e->getMessage()]);
            return Response::json(503, ['status' => 'error']);   // no internals leaked
        }
    }
}
