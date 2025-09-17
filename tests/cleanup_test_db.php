<?php

/**
 * Test veritabanı temizleme scripti
 * Bu script test veritabanını ve tablolarını temizler
 */

require_once __DIR__ . '/../vendor/autoload.php';

use nsql\database\Config;

// Test ortamını ayarla
Config::set_environment('testing');

// Veritabanı bağlantı bilgileri
$host = Config::get('db_host', 'localhost');
$user = Config::get('db_user', 'root');
$pass = Config::get('db_pass', '');
$test_db = Config::get('db_name', 'nsql_test_db');

try {
    // Ana veritabanına bağlan
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Test veritabanını sil
    $pdo->exec("DROP DATABASE IF EXISTS `$test_db`");
    
    echo "✅ Test veritabanı '$test_db' başarıyla silindi.\n";
    echo "✅ Temizlik tamamlandı!\n";

} catch (PDOException $e) {
    echo "❌ Veritabanı hatası: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "❌ Genel hata: " . $e->getMessage() . "\n";
    exit(1);
}
