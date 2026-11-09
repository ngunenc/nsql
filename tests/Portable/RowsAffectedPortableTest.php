<?php

namespace Tests\Portable;

use Tests\Support\PortableTestCase;

/**
 * rows_affected() parçalı yazmalarda toplamı verir; get_var() falsy değerleri "satır yok" ile karıştırmaz.
 */
class RowsAffectedPortableTest extends PortableTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->create_table('p_rows', ['code VARCHAR(20) NOT NULL UNIQUE', 'name VARCHAR(20) NOT NULL']);
    }

    /**
     * Sürücünün yer tutucu sınırını aşıp en az iki parçaya bölünecek kadar satır.
     *
     * @return list<array{code: string, name: string}>
     */
    private function rows_over_bind_limit(int $columns_per_row): array
    {
        $count = intdiv($this->db->max_bound_params(), $columns_per_row) + 5;
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['code' => 'c' . $i, 'name' => 'n'];
        }

        return $rows;
    }

    public function test_insert_many_over_multiple_chunks_reports_total(): void
    {
        $rows = $this->rows_over_bind_limit(2);

        $total = $this->db->table('p_rows')->insert_many($rows);

        $this->assertSame(count($rows), $total);
        $this->assertSame($total, $this->db->rows_affected());
    }

    public function test_upsert_over_multiple_chunks_reports_total(): void
    {
        // upsert parçaları insert_many'den küçüktür (güncellenen kolonlar da hesaba katılır); bu satır sayısı da bölünür
        $rows = $this->rows_over_bind_limit(2);

        $total = $this->db->table('p_rows')->upsert($rows, ['name'], ['code']);

        $this->assertGreaterThan(0, $total);
        $this->assertSame($total, $this->db->rows_affected());
    }

    public function test_get_var_returns_falsy_values_instead_of_null(): void
    {
        $this->db->insert('INSERT INTO p_rows (code, name) VALUES (?, ?)', ['a', '0']);

        $this->assertSame(0, (int) $this->db->get_var('SELECT COUNT(*) FROM p_rows WHERE code = ?', ['yok']));
        $this->assertNotNull($this->db->get_var('SELECT name FROM p_rows WHERE code = ?', ['a']));
        $this->assertSame('0', (string) $this->db->get_var('SELECT name FROM p_rows WHERE code = ?', ['a']));
        $this->assertNull($this->db->get_var('SELECT name FROM p_rows WHERE code = ?', ['yok']));
    }

    public function test_get_var_returns_boolean_false_on_pgsql(): void
    {
        if (self::driver() !== 'pgsql') {
            $this->markTestSkipped('pdo_pgsql boolean sütunları PHP bool döndürür; yalnızca PostgreSQL');
        }
        $this->create_table('p_bool', ['flag BOOLEAN NOT NULL']);
        $this->db->insert('INSERT INTO p_bool (flag) VALUES (FALSE)');

        $this->assertFalse($this->db->get_var('SELECT flag FROM p_bool'));
    }
}
