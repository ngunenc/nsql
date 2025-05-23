<?php

namespace Tests;

use Nsql\Database\nsql;
use PHPUnit\Framework\TestCase;

class NsqlTest extends TestCase
{
    private ?nsql $db = null;

    protected function setUp(): void
    {
        $this->db = new nsql(
            host: 'localhost',
            db: 'test_db',
            user: 'test_user',
            pass: 'test_pass'
        );
    }

    protected function tearDown(): void
    {
        $this->db = null;
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
}
