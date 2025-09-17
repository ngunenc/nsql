<?php

/**
 * Test veritabanı kurulum scripti
 * Bu script test veritabanını oluşturur ve gerekli tabloları hazırlar
 */

require_once __DIR__ . '/../vendor/autoload.php';

use nsql\database\config;

// Test ortamını ayarla
config::set_environment('testing');

// Veritabanı bağlantı bilgileri
$host = config::get('db_host', 'localhost');
$user = config::get('db_user', 'root');
$pass = config::get('db_pass', '');
$test_db = config::get('db_name', 'nsql_test_db');

try {
    // Ana veritabanına bağlan (test veritabanını oluşturmak için)
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Test veritabanını oluştur
    $pdo->exec("DROP DATABASE IF EXISTS `$test_db`");
    $pdo->exec("CREATE DATABASE `$test_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    
    echo "✅ Test veritabanı '$test_db' başarıyla oluşturuldu.\n";

    // Test veritabanına bağlan
    $pdo->exec("USE `$test_db`");

    // Test tablolarını oluştur
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            email VARCHAR(100) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS test_table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            value TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    echo "✅ Test tabloları başarıyla oluşturuldu.\n";
    echo "✅ Test ortamı hazır!\n";

} catch (PDOException $e) {
    echo "❌ Veritabanı hatası: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "❌ Genel hata: " . $e->getMessage() . "\n";
    exit(1);
}
