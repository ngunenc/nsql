<?php

namespace Tests\Fixtures\Migrations;

use nsql\database\base_migration;

class create_users_table extends base_migration
{
    public function up(): void
    {
        $this->db()->query("CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            email VARCHAR(255) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            last_login TIMESTAMP NULL,
            status ENUM('active', 'inactive', 'suspended') DEFAULT 'inactive'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $this->db()->query('DROP TABLE IF EXISTS users');
    }

    public function get_description(): string
    {
        return 'Create users table';
    }
}
