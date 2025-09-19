<?php

$ctx = require __DIR__ . '/bootstrap.php';
/** @var \nsql\database\nsql $nsql */
/** @var PDO $pdo */
$nsql = $ctx['nsql'];

// LIMIT kullanma; get_yield LIMIT/OFFSET kabul etmez
$sql = "SELECT * FROM bench_users"; // büyük set; streaming için

$rows = [];

// nsql -> get_yield (generator)
$start = timer_start();
$count = 0;
foreach ($nsql->get_yield($sql) as $row) {
    $count++;
    if (($count % 10000) === 0) {
        $snap = memory_snapshot();
    }
}
$gen_ms = round(timer_end($start), 2);
$gen_mem = memory_snapshot();

// nsql -> get_results (array)
$start = timer_start();
$all = $nsql->get_results($sql);
$arr_ms = round(timer_end($start), 2);
$arr_mem = memory_snapshot();

print_results([
    [
        'mode' => 'generator',
        'time_ms' => $gen_ms,
        'mem_peak' => format_bytes($gen_mem['peak']),
    ],
    [
        'mode' => 'array',
        'time_ms' => $arr_ms,
        'mem_peak' => format_bytes($arr_mem['peak']),
    ],
]);


