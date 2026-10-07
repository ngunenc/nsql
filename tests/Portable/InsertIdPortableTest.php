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
        } else {
            $this->db->query('DROP TABLE IF EXISTS p_ids');
            $this->db->query('DROP TABLE IF EXISTS p_ids_audit');
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

    public function test_trigger_inserting_into_other_table_does_not_change_id(): void
    {
        $this->create_audit_trigger();

        $this->assertSame(1, $this->db->table('p_ids')->insert(['name' => 'a']));
        $this->assertSame(1, $this->db->insert_id());

        $sequence = self::driver() === 'pgsql' ? 'p_ids_id_seq' : null;
        $this->assertSame(2, $this->db->insert('INSERT INTO p_ids (name) VALUES (?)', ['b'], $sequence));

        $audit_ids = array_map(
            static fn (object $row): int => (int) $row->id,
            $this->db->get_results('SELECT id FROM p_ids_audit ORDER BY id')
        );
        $this->assertSame([101, 102], $audit_ids);
    }

    /**
     * Audit tablosunun id'si 101'den başlar; sürücü trigger'ın eklediği id'yi döndürürse test bunu yakalar.
     */
    private function create_audit_trigger(): void
    {
        $this->db->query('DROP TABLE IF EXISTS p_ids_audit');

        switch (self::driver()) {
            case 'pgsql':
                $this->db->query('CREATE TABLE p_ids_audit (id SERIAL PRIMARY KEY, ref INT)');
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

                return;

            case 'sqlite':
                $this->db->query('CREATE TABLE p_ids_audit (id INTEGER PRIMARY KEY AUTOINCREMENT, ref INT)');
                $this->db->query("INSERT INTO sqlite_sequence (name, seq) VALUES ('p_ids_audit', 100)");
                $this->db->query('CREATE TRIGGER p_ids_audit_trg AFTER INSERT ON p_ids BEGIN INSERT INTO p_ids_audit (ref) VALUES (NEW.id); END');

                return;

            default:
                $this->db->query('CREATE TABLE p_ids_audit (id INT AUTO_INCREMENT PRIMARY KEY, ref INT) ENGINE=InnoDB AUTO_INCREMENT=101');
                // CREATE TRIGGER MySQL'in prepared statement protokolünde desteklenmez
                $pdo = $this->db->get_pdo();
                $this->assertNotNull($pdo);
                $pdo->exec('CREATE TRIGGER p_ids_audit_trg AFTER INSERT ON p_ids FOR EACH ROW INSERT INTO p_ids_audit (ref) VALUES (NEW.id)');
        }
    }
}
