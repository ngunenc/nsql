<?php

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;
use nsql\database\nsql;

class CrudIntegrationTest extends DatabaseTestCase
{
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
        // test_table'da name sütunu NOT NULL olabilir, bu yüzden value sütununu kullan
        $id = $this->db->insert(
            "INSERT INTO test_table (name, value) VALUES (:name, :value)",
            ['name' => 'test', 'value' => null]
        );
        
        $this->assertIsInt($id);
        $this->assertTrue($id > 0);
        
        $row = $this->db->get_row(
            "SELECT * FROM test_table WHERE id = :id",
            ['id' => $id]
        );
        
        // NULL değerlerin doğru şekilde işlendiğini kontrol et
        $this->assertNotNull($row);
        $this->assertNull($row->value); // NULL değerin doğru işlendiğini kontrol et
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
}
