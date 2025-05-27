<?php

namespace Tests;

use nsql\database\nsql;
use PHPUnit\Framework\TestCase;

class nsql_test extends TestCase
{
    private ?nsql $db = null;
    private static bool $migrated = false;

    protected function setUp(): void
    {
        // Test ortamı için .env.testing dosyasını kullan
        putenv('ENV=testing');
        
        $this->db = new nsql(
            host: getenv('DB_HOST') ?: 'localhost',
            db: getenv('DB_NAME') ?: 'nsql_test_db',
            user: getenv('DB_USER') ?: 'root',
            pass: getenv('DB_PASS') ?: ''
        );

        if (!self::$migrated) {
            $this->runMigrations();
            self::$migrated = true;
        }
    }

    private function runMigrations(): void
    {
        try {
            $migration = new \nsql\database\migrations\create_users_table();
            $migration->up();
        } catch (\Exception $e) {
            $this->markTestSkipped('Migration failed: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->db) {
            $this->db->query('TRUNCATE TABLE users');
            $this->db = null;
        }
    }

    public function testConnection()
    {
        $this->assertInstanceOf(nsql::class, $this->db);
    }

    public function testQueryCache()
    {
        $result1 = $this->db->get_results("SELECT * FROM test_table");
        $result2 = $this->db->get_results("SELECT * FROM test_table");
        $this->assertEquals($result1, $result2);
    }

    public function testConnectionPool()
    {
        $stats = nsql::getPoolStats();
        $this->assertArrayHasKey('active_connections', $stats);
        $this->assertArrayHasKey('idle_connections', $stats);
    }
    
    public function testCRUD()
    {
        // Insert
        $id = $this->db->insert(
            "INSERT INTO test_table (name) VALUES (:name)",
            ['name' => 'Test Name']
        );
        $this->assertIsInt($id);
        
        // Read
        $row = $this->db->get_row(
            "SELECT * FROM test_table WHERE id = :id",
            ['id' => $id]
        );
        $this->assertEquals('Test Name', $row->name);
        
        // Update
        $result = $this->db->update(
            "UPDATE test_table SET name = :name WHERE id = :id",
            ['name' => 'Updated Name', 'id' => $id]
        );
        $this->assertTrue($result);
        
        // Delete
        $result = $this->db->delete(
            "DELETE FROM test_table WHERE id = :id",
            ['id' => $id]
        );
        $this->assertTrue($result);
    }

    public function testSecurity()
    {
        // XSS koruması testi
        $input = '<script>alert("xss")</script>';
        $escaped = nsql::escapeHtml($input);
        $this->assertNotEquals($input, $escaped);
        
        // CSRF token testi
        $token = nsql::generateCsrfToken();
        $this->assertTrue(nsql::validateCsrfToken($token));
        
        // Şifreleme testi
        $encryption = new \nsql\database\security\encryption();
        $data = 'sensitive_data';
        $encrypted = $encryption->encrypt($data);
        $decrypted = $encryption->decrypt($encrypted);
        $this->assertEquals($data, $decrypted);
    }
}
