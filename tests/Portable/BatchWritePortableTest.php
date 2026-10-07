<?php

namespace Tests\Portable;

use nsql\database\exceptions\QueryException;
use Tests\Support\PortableTestCase;

class BatchWritePortableTest extends PortableTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->create_table('p_batch', ['name VARCHAR(50) NOT NULL']);
    }

    protected function tearDown(): void
    {
        $this->db->set_throw_on_error(null);
        parent::tearDown();
    }

    private function names(): array
    {
        return array_map(fn ($r) => $r->name, $this->db->get_results('SELECT name FROM p_batch ORDER BY id'));
    }

    public function test_failed_batch_insert_keeps_outer_transaction(): void
    {
        $this->db->set_throw_on_error(false);
        $this->db->begin();
        $this->db->insert('INSERT INTO p_batch (name) VALUES (?)', ['outer']);

        try {
            $this->db->batch_insert('p_batch', [['name' => null]]);
            $this->fail('QueryException bekleniyordu');
        } catch (QueryException) {
        }

        $this->assertSame(1, $this->db->get_transaction_level());
        $this->assertTrue($this->db->commit());
        $this->assertSame(['outer'], $this->names());
    }
}
