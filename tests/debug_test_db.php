<?php

/**
 * Test veritabanı debug scripti
 */

require_once __DIR__ . '/../vendor/autoload.php';

use nsql\database\Config;

// Test ortamını ayarla
Config::set_environment('testing');
Config::set_project_root(dirname(__DIR__));

// Veritabanı bağlantı bilgileri
$host = Config::get('db_host', 'localhost');
$user = Config::get('db_user', 'root');
$pass = Config::get('db_pass', '');
$test_db = Config::get('db_name', 'nsql_test_db');

try {
    // Test veritabanına bağlan
    $pdo = new PDO("mysql:host=$host;dbname=$test_db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "✅ Test veritabanına bağlandı: $test_db\n";

    // Tabloları listele
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "📋 Tablolar: " . implode(', ', $tables) . "\n";

    // test_table'ı kontrol et
    if (in_array('test_table', $tables)) {
        $count = $pdo->query("SELECT COUNT(*) FROM test_table")->fetchColumn();
        echo "📊 test_table kayıt sayısı: $count\n";

        if ($count > 0) {
            $rows = $pdo->query("SELECT * FROM test_table LIMIT 5")->fetchAll();
            echo "📄 İlk 5 kayıt:\n";
            foreach ($rows as $row) {
                echo "  - ID: {$row['id']}, Name: {$row['name']}\n";
            }
        }
    } else {
        echo "❌ test_table bulunamadı!\n";
    }

    // users tablosunu kontrol et
    if (in_array('users', $tables)) {
        $count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        echo "📊 users kayıt sayısı: $count\n";
    }
} catch (PDOException $e) {
    echo "❌ Veritabanı hatası: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "❌ Genel hata: " . $e->getMessage() . "\n";
    exit(1);
}
