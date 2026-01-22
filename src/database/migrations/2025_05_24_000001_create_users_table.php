<?php

namespace nsql\database\migrations;

use nsql\database\migration;
use nsql\database\nsql;

class create_users_table implements migration
{
    private nsql $db;

    public function __construct()
    {
        $this->db = new nsql();
    }

    public function up(): void
    {
        try {
            $sql = "CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) NOT NULL UNIQUE,
                email VARCHAR(255) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                last_login TIMESTAMP NULL,
                status ENUM('active', 'inactive', 'suspended') DEFAULT 'inactive'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            $this->db->query($sql);
        } catch (\Exception $e) {
            throw new \RuntimeException("Migration failed: " . $e->getMessage());
        }
    }

    public function down(): void
    {
        try {
            $sql = "DROP TABLE IF EXISTS users";
            $this->db->query($sql);
        } catch (\Exception $e) {
            throw new \RuntimeException("Migration rollback failed: " . $e->getMessage());
        }
    }

    public function get_description(): string
    {
        return 'Create users table';
    }

    public function get_dependencies(): array
    {
        return []; // Bu migration bağımlılığı yok
    }
}
