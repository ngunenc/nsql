<?php

// Benchmark bootstrap

require_once __DIR__ . '/../vendor/autoload.php';

use nsql\database\config;
use nsql\database\nsql;

/**
 * Ortak bağlantıları hazırlar ve yardımcı fonksiyonlar sağlar
 */

// Ortam
config::set_environment(getenv('ENV') ?: 'development');

// Bağlantı bilgileri (.env veya varsayılanlar)
$db_host = config::get('db_host', 'localhost');
$db_name = config::get('db_name', 'etiyop');
$db_user = config::get('db_user', 'root');
$db_pass = config::get('db_pass', '');
$db_charset = config::get('db_charset', 'utf8mb4');

// DSN
$dsn = "mysql:host={$db_host};dbname={$db_name};charset={$db_charset}";

// nsql bağlantısı
$nsql = new nsql();

// PDO bağlantısı (karşılaştırma için)
$pdo = new PDO($dsn, (string)$db_user, (string)$db_pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

// ezSQL (opsiyonel) - mevcutsa dahil et
$ezsql = null;
if (class_exists('ezSQL_mysqli') || class_exists('ezSQL_pdo')) {
    // Projede ezSQL yoksa bu bölüm atlanır; kullanıcı eklerse otomatik devreye girer
    // Burada ezSQL örneği oluşturmayı kullanıcıya bırakıyoruz
}

/**
 * Basit süre ölçer
 */
function timer_start(): float { return microtime(true); }
function timer_end(float $start): float { return (microtime(true) - $start) * 1000.0; }

/**
 * Bellek yardımcıları
 */
function memory_snapshot(): array {
    return [
        'current' => memory_get_usage(true),
        'peak' => memory_get_peak_usage(true),
    ];
}

function format_bytes(int $bytes): string {
    $units = ['B','KB','MB','GB','TB'];
    $pow = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
    $pow = min($pow, count($units) - 1);
    $bytes = $bytes / (1 << (10 * $pow));
    return round($bytes, 2) . ' ' . $units[$pow];
}

/**
 * Sonuçları tablo biçiminde yazdırma
 */
function print_results(array $rows): void {
    $headers = array_keys(reset($rows));
    $widths = [];
    foreach ($headers as $h) { $widths[$h] = strlen($h); }
    foreach ($rows as $row) {
        foreach ($row as $k => $v) {
            $widths[$k] = max($widths[$k], strlen((string)$v));
        }
    }
    $line = function() use ($widths) {
        echo '+';
        foreach ($widths as $w) echo str_repeat('-', $w + 2) . '+';
        echo PHP_EOL;
    };
    $line();
    echo '| ';
    foreach ($headers as $h) echo str_pad($h, $widths[$h]) . ' | ';
    echo PHP_EOL;
    $line();
    foreach ($rows as $row) {
        echo '| ';
        foreach ($headers as $h) echo str_pad((string)$row[$h], $widths[$h]) . ' | ';
        echo PHP_EOL;
    }
    $line();
}

return [
    'nsql' => $nsql,
    'pdo' => $pdo,
    'ezsql' => $ezsql,
    'dsn' => $dsn,
];


