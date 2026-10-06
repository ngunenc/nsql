<?php

namespace Tests\Portable;

use Tests\Support\PortableTestCase;

class InsertIdPortableTest extends PortableTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->create_table('p_ids', ['name VARCHAR(50) NOT NULL']);
    }

    protected function tearDown(): void
    {
        if (self::driver() === 'pgsql') {
            $this->db->query('DROP TABLE IF EXISTS p_ids CASCADE');
            $this->db->query('DROP TABLE IF EXISTS p_ids_audit');
            $this->db->query('DROP FUNCTION IF EXISTS p_ids_audit_fn()');
        }
        parent::tearDown();
    }

    public function test_query_builder_insert_returns_int_id(): void
    {
        $first = $this->db->table('p_ids')->insert(['name' => 'a']);
        $second = $this->db->table('p_ids')->insert(['name' => 'b']);

        $this->assertSame(1, $first);
        $this->assertSame(2, $second);
        $this->assertSame(2, $this->db->insert_id());
    }

    public function test_raw_insert_returns_int_id(): void
    {
        $this->assertSame(1, $this->db->insert('INSERT INTO p_ids (name) VALUES (?)', ['a']));
    }

    public function test_pgsql_trigger_advancing_other_sequence_does_not_change_id(): void
    {
        if (self::driver() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL trigger / RETURNING senaryosu');
        }

        $this->db->query('DROP TABLE IF EXISTS p_ids_audit');
        $this->db->query('CREATE TABLE p_ids_audit (id SERIAL PRIMARY KEY, ref INT)');
        // audit sequence'ini ilerlet: lastval() bundan sonra audit id'si (>= 101) döndürür
        $this->db->query("SELECT setval('p_ids_audit_id_seq', 100)");
        $this->db->query(<<<'SQL'
            CREATE OR REPLACE FUNCTION p_ids_audit_fn() RETURNS trigger AS $$
            BEGIN
                INSERT INTO p_ids_audit (ref) VALUES (NEW.id);
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->db->query('CREATE TRIGGER p_ids_audit_trg AFTER INSERT ON p_ids FOR EACH ROW EXECUTE FUNCTION p_ids_audit_fn()');

        $this->assertSame(1, $this->db->table('p_ids')->insert(['name' => 'a']));
        $this->assertSame(1, $this->db->insert_id());

        $raw = $this->db->insert('INSERT INTO p_ids (name) VALUES (?)', ['b'], 'p_ids_id_seq');
        $this->assertSame(2, $raw);
    }
}
