<?php

namespace nsql\database\migrations;

use nsql\database\migration;
use nsql\database\nsql;

class create_test_table implements migration
{
    private nsql $db;

    public function __construct()
    {
        $this->db = new nsql();
    }

    public function up(): void
    {
        try {
            $sql = "CREATE TABLE IF NOT EXISTS test_table (
				id INT AUTO_INCREMENT PRIMARY KEY,
				name VARCHAR(255) NOT NULL,
				created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $this->db->query($sql);
        } catch (\Exception $e) {
            throw new \RuntimeException("Migration failed: " . $e->getMessage());
        }
    }

    public function down(): void
    {
        try {
            $sql = "DROP TABLE IF EXISTS test_table";
            $this->db->query($sql);
        } catch (\Exception $e) {
            throw new \RuntimeException("Migration rollback failed: " . $e->getMessage());
        }
    }

    public function get_description(): string
    {
        return 'Create test_table for unit tests';
    }

    public function get_dependencies(): array
    {
        return []; // Bu migration bağımlılığı yok
    }
}
