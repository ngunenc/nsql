<?php

/**
 * Health Check Endpoint
 *
 * Kullanım: GET /health.php
 * Auth: Authorization: Bearer <token> | X-NSQL-Monitoring-Token | ?token=
 * Env: NSQL_MONITORING_TOKEN (zorunlu), NSQL_MONITORING_ENABLED=false ile kapatılabilir
 */

require_once __DIR__ . '/../vendor/autoload.php';

use nsql\database\nsql;
use nsql\database\monitoring\endpoint_guard;
use nsql\database\monitoring\health_check;

header('Content-Type: application/json');
endpoint_guard::protect();

try {
    $db = new nsql();
    $health_check = new health_check($db);
    $result = $health_check->check();

    http_response_code($result['status'] === 'healthy' ? 200 : 503);
    echo json_encode($result, JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    endpoint_guard::fail_closed($e, 503);
}
