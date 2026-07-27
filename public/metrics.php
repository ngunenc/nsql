<?php

/**
 * Metrics Endpoint
 *
 * Kullanım: GET /metrics.php
 * Auth: Authorization: Bearer <token> | X-NSQL-Monitoring-Token | ?token=
 * Env: NSQL_MONITORING_TOKEN (zorunlu), NSQL_MONITORING_ENABLED=false ile kapatılabilir
 */

require_once __DIR__ . '/../vendor/autoload.php';

use nsql\database\nsql;
use nsql\database\monitoring\endpoint_guard;
use nsql\database\monitoring\metrics;

header('Content-Type: application/json');
endpoint_guard::protect();

try {
    $db = new nsql();
    $metrics = new metrics($db);
    $result = $metrics->get_all();

    http_response_code(200);
    echo json_encode($result, JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    endpoint_guard::fail_closed($e, 500);
}
