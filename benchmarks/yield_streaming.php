<?php

// get_yield (unbuffered / LIMIT-OFFSET) ve chunk_by_id karşılaştırması.
// Satır sayısı: BENCH_ROWS ortam değişkeni (varsayılan 200000; 1M için BENCH_ROWS=1000000).

$ctx = require __DIR__ . '/bootstrap.php';
/** @var \nsql\database\nsql $nsql */
/** @var PDO $pdo */
$nsql = $ctx['nsql'];
$pdo = $ctx['pdo'];

$target = (int) (getenv('BENCH_ROWS') ?: 200000);
$count = (int) $pdo->query('SELECT COUNT(*) FROM bench_users')->fetchColumn();
while ($count < $target) {
    $batch = min($count, $target - $count);
    $pdo->exec("INSERT INTO bench_users (name, email, active) SELECT name, email, active FROM bench_users LIMIT {$batch}");
    $count += $batch;
}

$sql = 'SELECT id, name, email FROM bench_users';
$modes = [
    'get_yield unbuffered' => fn () => $nsql->get_yield($sql, [], true),
    'get_yield limit/offset' => fn () => $nsql->get_yield($sql, [], false),
    'chunk_by_id(5000)' => function () use ($nsql, $sql) {
        foreach ($nsql->chunk_by_id($sql, [], 'id', 5000) as $chunk) {
            yield from $chunk;
        }
    },
];

$results = [];
foreach ($modes as $name => $factory) {
    gc_collect_cycles();
    memory_reset_peak_usage();
    $base = memory_get_usage();
    $start = timer_start();
    $rows = 0;
    foreach ($factory() as $row) {
        $rows++;
    }
    $results[] = [
        'mode' => $name,
        'rows' => $rows,
        'time_ms' => round(timer_end($start), 2),
        'peak_delta' => format_bytes(max(0, memory_get_peak_usage() - $base)),
    ];
}

print_results($results);
