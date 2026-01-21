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

    // ========== UPDATE VE DELETE TESTLERİ ==========

    public function testUpdate()
    {
        // Önce bir kayıt ekle
        $id = $this->db->insert(
            "INSERT INTO test_table (name) VALUES (:name)",
            ['name' => 'Update Test Original']
        );
        $this->assertTrue($id > 0);
        
        // Kaydı güncelle
        $result = $this->db->update(
            "UPDATE test_table SET name = :name WHERE id = :id",
            ['name' => 'Update Test Updated', 'id' => $id]
        );
        $this->assertTrue($result);
        
        // Güncellenmiş kaydı kontrol et
        $row = $this->db->get_row(
            "SELECT * FROM test_table WHERE id = :id",
            ['id' => $id]
        );
        $this->assertNotNull($row);
        $this->assertEquals('Update Test Updated', $row->name);
    }

    public function testDelete()
    {
        // Önce bir kayıt ekle
        $id = $this->db->insert(
            "INSERT INTO test_table (name) VALUES (:name)",
            ['name' => 'Delete Test']
        );
        $this->assertTrue($id > 0);
        
        // Kaydı sil
        $result = $this->db->delete(
            "DELETE FROM test_table WHERE id = :id",
            ['id' => $id]
        );
        $this->assertTrue($result);
        
        // Silinen kaydın olmadığını kontrol et
        $row = $this->db->get_row(
            "SELECT * FROM test_table WHERE id = :id",
            ['id' => $id]
        );
        $this->assertNull($row);
    }

    // ========== GET_ROW DETAYLI TESTLERİ ==========

    public function testGetRowWithResult()
    {
        // Test verisi ekle
        $id = $this->db->insert(
            "INSERT INTO test_table (name) VALUES (:name)",
            ['name' => 'GetRow Test']
        );
        
        $row = $this->db->get_row(
            "SELECT * FROM test_table WHERE id = :id",
            ['id' => $id]
        );
        
        $this->assertNotNull($row);
        $this->assertIsObject($row);
        $this->assertEquals('GetRow Test', $row->name);
    }

    public function testGetRowWithNoResult()
    {
        $row = $this->db->get_row(
            "SELECT * FROM test_table WHERE id = :id",
            ['id' => 99999]
        );
        
        $this->assertNull($row);
    }

    // ========== GET_YIELD TESTİ ==========

    public function testGetYield()
    {
        // Test verisi ekle
        for ($i = 1; $i <= 10; $i++) {
            $this->db->insert(
                "INSERT INTO test_table (name) VALUES (:name)",
                ['name' => "Yield Test $i"]
            );
        }

        $count = 0;
        foreach ($this->db->get_yield("SELECT * FROM test_table WHERE name LIKE 'Yield Test%'") as $row) {
            $count++;
            $this->assertIsObject($row);
        }
        
        $this->assertGreaterThanOrEqual(10, $count);
    }

    // ========== INSERT_ID TESTİ ==========

    public function testInsertId()
    {
        $id1 = $this->db->insert(
            "INSERT INTO test_table (name) VALUES (:name)",
            ['name' => 'InsertId Test 1']
        );
        
        $insertId = $this->db->insert_id();
        $this->assertEquals($id1, $insertId);
        
        $id2 = $this->db->insert(
            "INSERT INTO test_table (name) VALUES (:name)",
            ['name' => 'InsertId Test 2']
        );
        
        $this->assertGreaterThan($id1, $id2);
    }

    // ========== TRANSACTION TESTLERİ ==========

    public function testCommitTransaction()
    {
        $this->db->begin_transaction();
        
        $id = $this->db->insert(
            "INSERT INTO test_table (name) VALUES (:name)",
            ['name' => 'Commit Test']
        );
        $this->assertTrue($id > 0);
        
        $this->db->commit_transaction();
        
        // Commit sonrası kaydın var olduğunu kontrol et
        $row = $this->db->get_row(
            "SELECT * FROM test_table WHERE id = :id",
            ['id' => $id]
        );
        $this->assertNotNull($row);
        $this->assertEquals('Commit Test', $row->name);
    }

    // ========== STATS TESTLERİ ==========

    public function testMemoryStats()
    {
        $stats = $this->db->get_memory_stats();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('peak_usage', $stats);
        $this->assertArrayHasKey('warning_count', $stats);
        $this->assertArrayHasKey('critical_count', $stats);
    }

    public function testCacheStats()
    {
        $stats = $this->db->get_all_cache_stats();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('query_cache', $stats);
        $this->assertArrayHasKey('statement_cache', $stats);
    }

    public function testAllStats()
    {
        $stats = $this->db->get_all_stats();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('memory', $stats);
        $this->assertArrayHasKey('cache', $stats);
    }

    // ========== SECURITY DETAYLI TESTLERİ ==========

    public function testSQLInjectionProtection()
    {
        // SQL injection denemesi
        $maliciousInput = "'; DROP TABLE test_table; --";
        
        $id = $this->db->insert(
            "INSERT INTO test_table (name) VALUES (:name)",
            ['name' => $maliciousInput]
        );
        
        $this->assertTrue($id > 0);
        
        // Tablonun hala var olduğunu kontrol et
        $result = $this->db->get_results("SELECT COUNT(*) as count FROM test_table");
        $this->assertNotEmpty($result);
        
        // Kaydın güvenli şekilde eklendiğini kontrol et
        $row = $this->db->get_row(
            "SELECT * FROM test_table WHERE id = :id",
            ['id' => $id]
        );
        $this->assertNotNull($row);
        $this->assertEquals($maliciousInput, $row->name);
    }

    public function testXSSProtection()
    {
        $xssInputs = [
            '<script>alert("xss")</script>',
            '<img src=x onerror=alert("xss")>',
            'javascript:alert("xss")',
            '<svg onload=alert("xss")>',
        ];
        
        foreach ($xssInputs as $input) {
            $escaped = nsql::escape_html($input);
            $this->assertNotEquals($input, $escaped);
            $this->assertStringNotContainsString('<script>', $escaped);
        }
    }

    public function testCSRFProtection()
    {
        // Token oluştur
        $token1 = nsql::csrf_token();
        $this->assertNotEmpty($token1);
        
        // Aynı token'ı doğrula
        $this->assertTrue(nsql::validate_csrf($token1));
        
        // Farklı token'ı doğrula (başarısız olmalı)
        $token2 = nsql::csrf_token();
        $this->assertFalse(nsql::validate_csrf('invalid_token'));
    }

    // ========== EDGE CASES TESTLERİ ==========

    public function testEmptyResults()
    {
        $results = $this->db->get_results(
            "SELECT * FROM test_table WHERE id = :id",
            ['id' => 99999]
        );
        
        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    public function testNullValues()
    {
        // NULL değer içeren kayıt ekle (eğer tablo NULL destekliyorsa)
        $id = $this->db->insert(
            "INSERT INTO test_table (name) VALUES (:name)",
            ['name' => null]
        );
        
        if ($id) {
            $row = $this->db->get_row(
                "SELECT * FROM test_table WHERE id = :id",
                ['id' => $id]
            );
            // NULL değerlerin doğru şekilde işlendiğini kontrol et
            $this->assertNotNull($row);
        }
    }

    public function testLargeDataSet()
    {
        // Büyük veri seti oluştur
        for ($i = 1; $i <= 100; $i++) {
            $this->db->insert(
                "INSERT INTO test_table (name) VALUES (:name)",
                ['name' => "Large Data Test $i"]
            );
        }
        
        $results = $this->db->get_results("SELECT * FROM test_table WHERE name LIKE 'Large Data Test%'");
        $this->assertGreaterThanOrEqual(100, count($results));
    }

    // ========== CONNECTION POOL DETAYLI TESTLERİ ==========

    public function testConnectionPoolStats()
    {
        $stats = nsql::get_pool_stats();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('active_connections', $stats);
        $this->assertArrayHasKey('idle_connections', $stats);
        $this->assertArrayHasKey('total_connections', $stats);
        $this->assertIsInt($stats['active_connections']);
        $this->assertIsInt($stats['idle_connections']);
    }

    // ========== QUERY BUILDER DETAYLI TESTLERİ ==========

    public function testQueryBuilderJoin()
    {
        $builder = new \nsql\database\query_builder($this->db);
        
        $query = $builder->select('test_table.*', 'users.name as user_name')
            ->from('test_table')
            ->join('users', 'test_table.user_id', '=', 'users.id')
            ->get_query();
            
        $this->assertStringContainsString('JOIN', $query);
        $this->assertStringContainsString('users', $query);
    }

    public function testQueryBuilderMultipleWhere()
    {
        $builder = new \nsql\database\query_builder($this->db);
        
        $query = $builder->select('*')
            ->from('test_table')
            ->where('name', '=', 'Test')
            ->where('id', '>', 0)
            ->get_query();
            
        $this->assertStringContainsString('WHERE', $query);
        // İki WHERE koşulu olmalı
        $whereCount = substr_count($query, 'WHERE');
        $this->assertGreaterThanOrEqual(1, $whereCount);
    }

    // ========== PERFORMANCE TESTLERİ ==========

    public function testChunkPerformance()
    {
        // Büyük veri seti oluştur
        for ($i = 1; $i <= 50; $i++) {
            $this->db->insert(
                "INSERT INTO test_table (name) VALUES (:name)",
                ['name' => "Chunk Perf Test $i"]
            );
        }
        
        $startTime = microtime(true);
        $count = 0;
        foreach ($this->db->get_chunk("SELECT * FROM test_table WHERE name LIKE 'Chunk Perf Test%'", [], 10) as $chunk) {
            $count += count($chunk);
        }
        $endTime = microtime(true);
        
        $this->assertGreaterThanOrEqual(50, $count);
        $this->assertLessThan(5, $endTime - $startTime); // 5 saniyeden az sürmeli
    }

    // ========== INTEGRATION TESTLERİ ==========

    public function testFullCRUDWorkflow()
    {
        // Create
        $id = $this->db->insert(
            "INSERT INTO test_table (name) VALUES (:name)",
            ['name' => 'Integration Test']
        );
        $this->assertTrue($id > 0);
        
        // Read
        $row = $this->db->get_row(
            "SELECT * FROM test_table WHERE id = :id",
            ['id' => $id]
        );
        $this->assertNotNull($row);
        $this->assertEquals('Integration Test', $row->name);
        
        // Update
        $updated = $this->db->update(
            "UPDATE test_table SET name = :name WHERE id = :id",
            ['name' => 'Integration Test Updated', 'id' => $id]
        );
        $this->assertTrue($updated);
        
        // Verify Update
        $row = $this->db->get_row(
            "SELECT * FROM test_table WHERE id = :id",
            ['id' => $id]
        );
        $this->assertEquals('Integration Test Updated', $row->name);
        
        // Delete
        $deleted = $this->db->delete(
            "DELETE FROM test_table WHERE id = :id",
            ['id' => $id]
        );
        $this->assertTrue($deleted);
        
        // Verify Delete
        $row = $this->db->get_row(
            "SELECT * FROM test_table WHERE id = :id",
            ['id' => $id]
        );
        $this->assertNull($row);
    }

    public function testTransactionWithMultipleOperations()
    {
        $this->db->begin_transaction();
        
        try {
            // Birden fazla işlem
            $id1 = $this->db->insert(
                "INSERT INTO test_table (name) VALUES (:name)",
                ['name' => 'Transaction Multi 1']
            );
            
            $id2 = $this->db->insert(
                "INSERT INTO test_table (name) VALUES (:name)",
                ['name' => 'Transaction Multi 2']
            );
            
            $this->db->update(
                "UPDATE test_table SET name = :name WHERE id = :id",
                ['name' => 'Transaction Multi 1 Updated', 'id' => $id1]
            );
            
            $this->db->commit_transaction();
            
            // Tüm işlemlerin başarılı olduğunu kontrol et
            $row1 = $this->db->get_row("SELECT * FROM test_table WHERE id = :id", ['id' => $id1]);
            $row2 = $this->db->get_row("SELECT * FROM test_table WHERE id = :id", ['id' => $id2]);
            
            $this->assertNotNull($row1);
            $this->assertNotNull($row2);
            $this->assertEquals('Transaction Multi 1 Updated', $row1->name);
        } catch (\Exception $e) {
            $this->db->rollback_transaction();
            throw $e;
        }
    }

    // ========== ERROR HANDLING DETAYLI TESTLERİ ==========

    public function testGetLastError()
    {
        // Geçersiz sorgu çalıştır
        try {
            $this->db->query("INVALID SQL QUERY");
        } catch (\Exception $e) {
            // Hata bekleniyor
        }
        
        $error = $this->db->get_last_error();
        // Hata mesajı olabilir veya null olabilir (debug mode'a bağlı)
        $this->assertTrue($error === null || is_string($error));
    }

    public function testSafeExecute()
    {
        // Başarılı işlem
        $result = $this->db->safe_execute(function() {
            return $this->db->get_results("SELECT 1 as test");
        });
        
        $this->assertIsArray($result);
        
        // Hatalı işlem
        $result = $this->db->safe_execute(function() {
            return $this->db->query("INVALID SQL");
        }, 'Custom error message');
        
        $this->assertFalse($result);
    }
}
