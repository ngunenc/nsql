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

    /**
     * @return list<array{name: string, code: int}>
     */
    private function wide_rows(int $count): array
    {
        $this->create_table('p_batch_wide', ['name VARCHAR(20) NOT NULL', 'code INT NOT NULL']);

        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['name' => 'n' . $i, 'code' => $i];
        }

        return $rows;
    }

    private function assert_wide_rows_inserted(int $count): void
    {
        $stats = $this->db->get_row('SELECT COUNT(*) AS c, SUM(code) AS s FROM p_batch_wide');
        $this->assertSame($count, (int) $stats->c);
        $this->assertSame(intdiv($count * ($count + 1), 2), (int) $stats->s);
    }

    public function test_batch_insert_splits_rows_over_driver_param_limit(): void
    {
        // 35.000 satır x 2 kolon = 70.000 placeholder: her sürücünün tek sorgu sınırını aşar
        $this->assertSame(35000, $this->db->batch_insert('p_batch_wide', $this->wide_rows(35000)));
        $this->assert_wide_rows_inserted(35000);
    }

    public function test_insert_many_splits_rows_over_driver_param_limit(): void
    {
        // 41.000 isimli placeholder: SQLite sınırını (32766) aşar; SQLite isimli bağlamada yavaş olduğu için küçük tutulur
        $this->assertSame(20500, $this->db->table('p_batch_wide')->insert_many($this->wide_rows(20500)));
        $this->assert_wide_rows_inserted(20500);
    }

    /**
     * upsert() tek ifade kuruyordu: 65.535 placeholder'ı aşan veri her sürücüde hata veriyordu.
     */
    public function test_upsert_splits_rows_over_driver_param_limit(): void
    {
        $this->create_table('p_batch_upsert', ['name VARCHAR(20) NOT NULL', 'code INT NOT NULL UNIQUE']);
        $rows = [];
        for ($i = 1; $i <= 35000; $i++) {
            $rows[] = ['name' => 'n' . $i, 'code' => $i];
        }

        $this->db->table('p_batch_upsert')->upsert($rows, ['name'], ['code']);
        $updated = array_map(static fn (array $r): array => ['name' => 'u' . $r['code'], 'code' => $r['code']], $rows);
        $this->db->table('p_batch_upsert')->upsert($updated, ['name'], ['code']);

        $stats = $this->db->get_row("SELECT COUNT(*) AS c, SUM(CASE WHEN name LIKE 'u%' THEN 1 ELSE 0 END) AS u FROM p_batch_upsert");
        $this->assertSame(35000, (int) $stats->c);
        $this->assertSame(35000, (int) $stats->u);
    }

    /**
     * SQLite'ta isimli placeholder maliyeti ifade başına karesel: parçalar 1000 parametreyle sınırlı.
     */
    public function test_sqlite_bulk_chunks_stay_small(): void
    {
        $chunks = (fn (array $rows): array => $this->chunk_rows($rows))->call($this->db->table('p_batch'), array_fill(0, 2500, ['a' => 1, 'b' => 2]));
        $expected = self::driver() === 'sqlite' ? 500 : 2500;
        $this->assertSame($expected, count($chunks[0]));
    }

    public function test_batch_insert_rejects_rows_with_different_columns(): void
    {
        $invalid_sets = [
            'eksik kolon' => [['name' => 'a', 'code' => 1], ['name' => 'b']],
            'fazla kolon' => [['name' => 'a'], ['name' => 'b', 'extra' => 1]],
            'farklı kolon' => [['name' => 'a'], ['title' => 'b']],
        ];

        foreach ($invalid_sets as $label => $rows) {
            try {
                $this->db->batch_insert('p_batch', $rows);
                $this->fail("QueryException bekleniyordu: {$label}");
            } catch (QueryException $e) {
                $this->assertStringContainsString('kolonları', $e->getMessage(), $label);
            }
        }

        $this->assertSame([], $this->names());
        $this->assertSame(0, $this->db->get_transaction_level());
    }

    public function test_batch_insert_accepts_same_columns_in_different_order(): void
    {
        $this->db->batch_insert('p_batch', [['name' => 'a'], ['name' => 'b']]);
        $this->create_table('p_batch_wide', ['name VARCHAR(20) NOT NULL', 'code INT NOT NULL']);
        $this->db->batch_insert('p_batch_wide', [['name' => 'x', 'code' => 1], ['code' => 2, 'name' => 'y']]);

        $this->assertSame(['a', 'b'], $this->names());
        $rows = $this->db->get_results('SELECT name, code FROM p_batch_wide ORDER BY code');
        $this->assertSame(['x', 'y'], array_map(fn ($r) => $r->name, $rows));
    }
}
