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
        // Test ortamını ayarla
        \nsql\database\config::set_environment('testing');

        $this->db = new nsql(
            host: \nsql\database\config::get('db_host', 'localhost'),
            db: \nsql\database\config::get('db_name', 'etiyop'),
            user: \nsql\database\config::get('db_user', 'root'),
            pass: \nsql\database\config::get('db_pass', '')
        );

        if (! self::$migrated) {
            $this->runMigrations();
            self::$migrated = true;
        }
    }

    private function runMigrations(): void
    {
        try {
            $users = new \nsql\database\migrations\create_users_table();
            $users->up();
            $test = new \nsql\database\migrations\create_test_table();
            $test->up();
        } catch (\Exception $e) {
            $this->markTestSkipped('Migration failed: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->db) {
            // Test verilerini korumak için tearDown'ı devre dışı bırak
            // $this->db->query('TRUNCATE TABLE users');
            // $this->db->query('TRUNCATE TABLE test_table');
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
        $stats = nsql::get_pool_stats();
        $this->assertArrayHasKey('active_connections', $stats);
        $this->assertArrayHasKey('idle_connections', $stats);
    }

    public function testCRUD()
    {
        // Basit query testi
        $result = $this->db->query("INSERT INTO test_table (name) VALUES ('Test Name')");
        $this->assertNotFalse($result, "Query başarısız: " . $this->db->get_last_error());

        // Read - tüm verileri oku
        $rows = $this->db->get_results("SELECT * FROM test_table");
        $this->assertNotEmpty($rows, "Hiç veri bulunamadı");
        $this->assertCount(1, $rows, "Beklenen 1 kayıt bulunamadı");
        $this->assertEquals('Test Name', $rows[0]->name);
    }

    public function testSecurity()
    {
        // XSS koruması testi
        $input = '<script>alert("xss")</script>';
        $escaped = nsql::escape_html($input);
        $this->assertNotEquals($input, $escaped);

        // CSRF token testi
        $token = \nsql\database\security\session_manager::get_csrf_token();
        $this->assertTrue(\nsql\database\security\session_manager::validate_csrf_token($token));

        // Şifreleme testi
        $encryption = new \nsql\database\security\encryption();
        $data = 'sensitive_data';
        $encrypted = $encryption->encrypt($data);
        $decrypted = $encryption->decrypt($encrypted);
        $this->assertEquals($data, $decrypted);
    }

    public function testTransaction()
    {
        $this->db->begin_transaction();
        
        $result = $this->db->insert(
            "INSERT INTO test_table (name) VALUES (:name)",
            ['name' => 'Transaction Test']
        );
        $this->assertTrue($result);
        $id = $this->db->insert_id();
        $this->assertIsInt($id);
        
        $this->db->rollback_transaction();
        
        $row = $this->db->get_row(
            "SELECT * FROM test_table WHERE id = :id",
            ['id' => $id]
        );
        $this->assertNull($row);
    }

    public function testChunkedFetch()
    {
        // Test verisi ekle
        for ($i = 1; $i <= 5; $i++) {
            $this->db->insert(
                "INSERT INTO test_table (name) VALUES (:name)",
                ['name' => "Test Item $i"]
            );
        }

        $count = 0;
        foreach ($this->db->get_chunk("SELECT * FROM test_table", [], 2) as $chunk) {
            $count += count($chunk);
        }
        
        $this->assertEquals(5, $count);
    }

    public function testQueryBuilder()
    {
        $builder = new \nsql\database\query_builder($this->db);
        
        $query = $builder->select('*')
            ->from('test_table')
            ->where('name', '=', 'Test Name')
            ->order_by('id', 'DESC')
            ->limit(10)
            ->get_query();
            
        $this->assertStringContainsString('SELECT * FROM test_table', $query);
        $this->assertStringContainsString('WHERE name =', $query);
        $this->assertStringContainsString('ORDER BY id DESC', $query);
        $this->assertStringContainsString('LIMIT 10', $query);
    }

    public function testErrorHandling()
    {
        $this->expectException(\Exception::class);
        $this->db->query("INVALID SQL QUERY");
    }
}
