<?php

/**
 * Health Check Endpoint
 *
 * Kullanım: GET /health.php
 * Auth: Authorization: Bearer <token> | X-NSQL-Monitoring-Token
 *       (?token= yalnızca NSQL_MONITORING_ALLOW_QUERY_TOKEN=true ile)
 * Env: NSQL_MONITORING_TOKEN (zorunlu), NSQL_MONITORING_ENABLED=false ile kapatılabilir
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use nsql\database\Nsql;
use nsql\database\monitoring\EndpointGuard;
use nsql\database\monitoring\HealthCheck;

header('Content-Type: application/json');
EndpointGuard::protect();

try {
    $db = new Nsql();
    $health_check = new HealthCheck($db);
    $result = $health_check->check();

    http_response_code($result['status'] === 'healthy' ? 200 : 503);
    echo json_encode($result, JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    EndpointGuard::fail_closed($e, 503);
}
