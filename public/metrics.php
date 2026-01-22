<?php

/**
 * Metrics Endpoint
 * 
 * Kullanım: GET /metrics.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use nsql\database\nsql;
use nsql\database\monitoring\metrics;

header('Content-Type: application/json');

try {
    $db = new nsql();
    $metrics = new metrics($db);
    $result = $metrics->get_all();
    
    http_response_code(200);
    echo json_encode($result, JSON_PRETTY_PRINT);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s'),
    ], JSON_PRETTY_PRINT);
}
