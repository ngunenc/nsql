<?php

$ctx = require __DIR__ . '/bootstrap.php';
/** @var \nsql\database\nsql $nsql */
/** @var PDO $pdo */
$nsql = $ctx['nsql'];
$pdo = $ctx['pdo'];

$cases = [
    ['name' => 'small_1k', 'limit' => 1000],
    ['name' => 'medium_10k', 'limit' => 10000],
];

$results = [];

foreach ($cases as $c) {
    $limit = (int)$c['limit'];
    $sql = "SELECT * FROM users LIMIT {$limit}"; // tablo artık seed ile dolu

    // nsql get_results
    $start = timer_start();
    $rows = $nsql->get_results($sql);
    $nsql_ms = round(timer_end($start), 2);
    $nsql_cnt = count($rows);

    // PDO fetchAll
    $start = timer_start();
    $stmt = $pdo->query($sql);
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $pdo_ms = round(timer_end($start), 2);
    $pdo_cnt = count($rows);

    $results[] = [
        'case' => $c['name'],
        'nsql_ms' => $nsql_ms,
        'pdo_ms' => $pdo_ms,
        'count' => $nsql_cnt . '/' . $pdo_cnt,
    ];
}

print_results($results);


