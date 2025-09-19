<?php

$ctx = require __DIR__ . '/bootstrap.php';
/** @var \nsql\database\nsql $nsql */
$nsql = $ctx['nsql'];

$query = "SELECT * FROM bench_users WHERE active = 1 LIMIT 1000";

// İlk çağrı (miss)
$start = timer_start();
$a = $nsql->get_results($query);
$first_ms = round(timer_end($start), 2);

// İkinci çağrı (hit beklenir)
$start = timer_start();
$b = $nsql->get_results($query);
$second_ms = round(timer_end($start), 2);

$stats = $nsql->get_all_cache_stats();

print_results([
    [
        'phase' => 'first',
        'time_ms' => $first_ms,
        'cache_hit_rate' => $stats['query_cache']['hit_rate'] ?? 0,
    ],
    [
        'phase' => 'second',
        'time_ms' => $second_ms,
        'cache_hit_rate' => $stats['query_cache']['hit_rate'] ?? 0,
    ],
]);


