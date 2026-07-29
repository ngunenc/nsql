<?php

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;
use nsql\database\nsql;

class TransactionIntegrationTest extends DatabaseTestCase
{
    public function testTransaction()
    {
        $this->db->begin_transaction();
        
        $id = $this->db->insert(
            "INSERT INTO test_table (name) VALUES (:name)",
            ['name' => 'Transaction Test']
        );
        $this->assertIsInt($id);
        $this->assertTrue($id > 0);
        
        $insertId = $this->db->insert_id();
        $this->assertEquals($id, $insertId);
        
        $this->db->rollback_transaction();
        
        $row = $this->db->get_row(
            "SELECT * FROM test_table WHERE id = :id",
            ['id' => $id]
        );
        $this->assertNull($row);
    }

    public function testNestedTransactionSavepoints(): void
    {
        $this->db->begin();
        $this->assertSame(1, $this->db->get_transaction_level());

        $outerId = $this->db->insert(
            "INSERT INTO test_table (name) VALUES (:name)",
            ['name' => 'Nested Outer']
        );

        $this->db->begin();
        $this->assertSame(2, $this->db->get_transaction_level());

        $innerId = $this->db->insert(
            "INSERT INTO test_table (name) VALUES (:name)",
            ['name' => 'Nested Inner']
        );

        $this->assertTrue($this->db->rollback());
        $this->assertSame(1, $this->db->get_transaction_level());

        $this->assertNull($this->db->get_row(
            "SELECT * FROM test_table WHERE id = :id",
            ['id' => $innerId]
        ));
        $this->assertNotNull($this->db->get_row(
            "SELECT * FROM test_table WHERE id = :id",
            ['id' => $outerId]
        ));

        $this->assertTrue($this->db->commit());
        $this->assertSame(0, $this->db->get_transaction_level());
        $this->assertNotNull($this->db->get_row(
            "SELECT * FROM test_table WHERE id = :id",
            ['id' => $outerId]
        ));
    }

    public function testCommitWithoutTransactionReturnsFalse(): void
    {
        $this->assertSame(0, $this->db->get_transaction_level());
        $this->assertFalse($this->db->commit());
        $this->assertFalse($this->db->rollback());
    }

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
}
