<?php

/**
 * Sorgu sıcak yolu: query cache kapalıyken get_row() / get_results() maliyeti, ham PDO ile karşılaştırma.
 * Kurulum gerektirmez; bellek içi SQLite disk G/Ç'sini ölçümden çıkarır, kütüphane ek yükü görünür.
 *
 *   php -d xdebug.mode=off benchmarks/hot_path.php [tekrar=20000]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use nsql\database\Config;
use nsql\database\Nsql;

$iterations = max(1, (int) ($argv[1] ?? 20000));

Config::set('query_cache_enabled', false);
Config::set('slow_query_threshold_ms', 0);

$db = new Nsql(db: ':memory:', driver: 'sqlite');
$db->query('CREATE TABLE bench_users (id INTEGER PRIMARY KEY, name VARCHAR(50), email VARCHAR(100))');
$rows = [];
for ($i = 1; $i <= 100; $i++) {
    $rows[] = ['name' => 'user' . $i, 'email' => "user{$i}@example.com"];
}
$db->batch_insert('bench_users', $rows);

$scenarios = [
    'get_row (isimli parametre)' => fn (int $i) => $db->get_row('SELECT * FROM bench_users WHERE id = :id', ['id' => $i % 100 + 1]),
    'get_results (10 satır)' => fn (int $i) => $db->get_results('SELECT id, name FROM bench_users WHERE id > ? LIMIT 10', [$i % 90]),
    'ham PDO get_row (referans)' => function (int $i) use ($db) {
        $stmt = $db->get_pdo()->prepare('SELECT * FROM bench_users WHERE id = :id');
        $stmt->execute(['id' => $i % 100 + 1]);

        return $stmt->fetch(PDO::FETCH_OBJ);
    },
];

printf("%-30s %12s %14s\n", 'Senaryo', 'Toplam (ms)', 'Sorgu başı (µs)');
foreach ($scenarios as $name => $run) {
    for ($i = 0; $i < 200; $i++) {
        $run($i);
    }

    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $run($i);
    }
    $elapsed_ns = hrtime(true) - $start;

    printf("%-30s %12.1f %14.2f\n", $name, $elapsed_ns / 1e6, $elapsed_ns / 1e3 / $iterations);
}
