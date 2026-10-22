<?php

/**
 * Metrics Endpoint
 *
 * Kullanım: GET /metrics.php
 * Auth: Authorization: Bearer <token> | X-NSQL-Monitoring-Token
 *       (?token= yalnızca NSQL_MONITORING_ALLOW_QUERY_TOKEN=true ile)
 * Env: NSQL_MONITORING_TOKEN (zorunlu), NSQL_MONITORING_ENABLED=false ile kapatılabilir
 *
 * Not: Sorgu ve cache sayaçları Nsql örneği başınadır. Bu örnek kendi (yeni) bağlantısının
 * değerlerini gösterir; uygulamanızın sayaçları için Metrics'i uygulamanın kullandığı örnekle
 * oluşturun (ör. `new Metrics(Nsql::connection())` uygulama içindeki bir yönetim rotasında).
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use nsql\database\Nsql;
use nsql\database\monitoring\EndpointGuard;
use nsql\database\monitoring\Metrics;

header('Content-Type: application/json');
EndpointGuard::protect();

try {
    $db = new Nsql();
    $metrics = new Metrics($db);
    $result = $metrics->get_all();

    http_response_code(200);
    echo json_encode($result, JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    EndpointGuard::fail_closed($e, 500);
}
