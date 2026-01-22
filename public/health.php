<?php

/**
 * Health Check Endpoint
 * 
 * Kullanım: GET /health.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use nsql\database\nsql;
use nsql\database\monitoring\health_check;

header('Content-Type: application/json');

try {
    $db = new nsql();
    $health_check = new health_check($db);
    $result = $health_check->check();
    
    http_response_code($result['status'] === 'healthy' ? 200 : 503);
    echo json_encode($result, JSON_PRETTY_PRINT);
} catch (\Exception $e) {
    http_response_code(503);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s'),
    ], JSON_PRETTY_PRINT);
}
