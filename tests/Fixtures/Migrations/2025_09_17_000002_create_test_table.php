<?php

namespace Tests\Fixtures\Migrations;

use nsql\database\BaseMigration;

class create_test_table extends BaseMigration
{
    public function up(): void
    {
        $this->db()->query("CREATE TABLE IF NOT EXISTS test_table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            value TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $this->db()->query('DROP TABLE IF EXISTS test_table');
    }

    public function get_description(): string
    {
        return 'Create test_table for integration tests';
    }
}
