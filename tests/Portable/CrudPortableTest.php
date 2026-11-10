<?php

namespace Tests\Portable;

use nsql\database\exceptions\QueryException;
use Tests\Support\PortableTestCase;

class CrudPortableTest extends PortableTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->create_table('p_users', ['name VARCHAR(50) NOT NULL', 'email VARCHAR(100) NULL', 'score INT NOT NULL DEFAULT 0']);
    }

    public function test_driver_is_reported(): void
    {
        $this->assertSame(self::driver(), $this->db->get_driver_name());
    }

    public function test_insert_returns_id_and_rows_are_readable(): void
    {
        $first = $this->db->insert('INSERT INTO p_users (name, email, score) VALUES (:name, :email, :score)', [
            'name' => 'Ali', 'email' => 'ali@example.com', 'score' => 10,
        ]);
        $second = $this->db->insert('INSERT INTO p_users (name, score) VALUES (?, ?)', ['Veli', 20]);

        $this->assertSame(1, (int) $first);
        $this->assertSame(2, (int) $second);
        $this->assertSame(2, (int) $this->db->insert_id());

        $row = $this->db->get_row('SELECT * FROM p_users WHERE id = ?', [1]);
        $this->assertSame('Ali', $row->name);
        $this->assertSame('ali@example.com', $row->email);

        $rows = $this->db->get_results('SELECT name FROM p_users ORDER BY id');
        $this->assertSame(['Ali', 'Veli'], array_map(fn ($r) => $r->name, $rows));

        $this->assertNull($this->db->get_row('SELECT * FROM p_users WHERE id = ?', [99]));
    }

    public function test_update_and_delete_report_affected_rows(): void
    {
        $this->db->insert('INSERT INTO p_users (name, score) VALUES (?, ?), (?, ?), (?, ?)', ['a', 1, 'b', 2, 'c', 3]);

        $this->db->set_throw_on_error(false);
        $this->assertTrue($this->db->update('UPDATE p_users SET score = score + 1 WHERE score >= ?', [2]));

        $this->db->set_throw_on_error(true);
        $this->assertSame(2, $this->db->update('UPDATE p_users SET score = score + 9 WHERE score >= ?', [2]));
        $this->assertSame(1, $this->db->delete('DELETE FROM p_users WHERE name = ?', ['a']));

        $scores = array_map(fn ($r) => (int) $r->score, $this->db->get_results('SELECT score FROM p_users ORDER BY id'));
        $this->assertSame([12, 13], $scores);
    }

    public function test_statement_returns_row_count(): void
    {
        $this->db->insert('INSERT INTO p_users (name) VALUES (?), (?)', ['a', 'b']);

        $this->assertSame(2, $this->db->statement('UPDATE p_users SET score = 5'));
    }

    public function test_batch_insert_and_update(): void
    {
        $inserted = $this->db->batch_insert('p_users', [
            ['name' => 'a', 'score' => 1],
            ['name' => 'b', 'score' => 2],
            ['name' => 'c', 'score' => 3],
        ]);
        $this->assertSame(3, $inserted);

        $updated = $this->db->batch_update('p_users', [
            ['id' => 1, 'score' => 100],
            ['id' => 3, 'score' => 300],
        ]);
        $this->assertSame(2, $updated);

        $scores = array_map(fn ($r) => (int) $r->score, $this->db->get_results('SELECT score FROM p_users ORDER BY id'));
        $this->assertSame([100, 2, 300], $scores);
    }

    /**
     * @return list<string> Callback süresince çalışan UPDATE ifadeleri
     */
    private function captured_updates(callable $fn): array
    {
        $queries = [];
        $this->db->on_query(function ($event) use (&$queries): void {
            if (str_starts_with($event->sql, 'UPDATE')) {
                $queries[] = $event->sql;
            }
        });
        $fn();

        return $queries;
    }

    /**
     * Aynı kolonları güncelleyen satırlar tek ifadede (CASE WHEN) yazılır: satır başına gidiş-dönüş yok.
     */
    public function test_batch_update_uses_single_statement_for_same_columns(): void
    {
        $this->db->batch_insert('p_users', [
            ['name' => 'a', 'score' => 1],
            ['name' => 'b', 'score' => 2],
            ['name' => 'c', 'score' => 3],
            ['name' => 'd', 'score' => 4],
        ]);

        $updated = 0;
        $queries = $this->captured_updates(function () use (&$updated): void {
            $updated = $this->db->batch_update('p_users', [
                ['id' => 1, 'name' => 'a2', 'score' => 100],
                ['id' => 3, 'name' => 'c2', 'score' => 300],
                ['id' => 4, 'name' => 'd2', 'score' => 400],
            ]);
        });

        $this->assertCount(1, $queries);
        $this->assertSame(3, $updated);
        $this->assertSame(3, $this->db->rows_affected());

        $rows = array_map(fn ($r) => $r->name . ':' . (int) $r->score, $this->db->get_results('SELECT name, score FROM p_users ORDER BY id'));
        $this->assertSame(['a2:100', 'b:2', 'c2:300', 'd2:400'], $rows);
    }

    public function test_batch_update_generates_case_when_sql(): void
    {
        $this->db->batch_insert('p_users', [['name' => 'a', 'score' => 1], ['name' => 'b', 'score' => 2]]);

        $queries = $this->captured_updates(fn () => $this->db->batch_update('p_users', [
            ['id' => 1, 'score' => 10],
            ['id' => 2, 'score' => 20],
        ]));

        $q = self::driver() === 'mysql' ? '`' : '"';
        $this->assertSame(
            ["UPDATE {$q}p_users{$q} SET {$q}score{$q} = CASE {$q}id{$q} WHEN ? THEN ? WHEN ? THEN ? ELSE {$q}score{$q} END"
                . " WHERE {$q}id{$q} IN (?, ?)"],
            $queries
        );
    }

    /**
     * Farklı kolon kümeleri ve yinelenen anahtarlar satır sırasıyla uygulanır (son yazan kazanır),
     * NULL değerler ve anahtarı tabloda olmayan satırlar eski davranışla aynıdır.
     */
    public function test_batch_update_keeps_row_order_semantics(): void
    {
        $this->db->batch_insert('p_users', [
            ['name' => 'a', 'score' => 1],
            ['name' => 'b', 'score' => 2],
            ['name' => 'c', 'score' => 3],
        ]);
        $this->db->statement("UPDATE p_users SET email = 'x@example.com'");

        $updated = 0;
        $queries = $this->captured_updates(function () use (&$updated): void {
            $updated = $this->db->batch_update('p_users', [
                ['id' => 1, 'score' => 10],
                ['id' => 2, 'score' => 20],
                ['id' => 2, 'email' => null],          // farklı kolon kümesi
                ['id' => 3, 'email' => 'c@example.com'],
                ['id' => 3, 'email' => 'son@example.com'], // yinelenen anahtar: son yazan kazanır
                ['id' => 999, 'email' => 'yok@example.com'], // eşleşen satır yok
                ['id' => 1],                            // güncellenecek kolon yok: atlanır
            ]);
        });

        // [1,2 score] + [2,3 email] + [3,999 email]
        $this->assertCount(3, $queries);
        $this->assertSame(5, $updated);
        $this->assertSame(5, $this->db->rows_affected());

        $rows = array_map(
            fn ($r) => (int) $r->score . ':' . ($r->email ?? 'NULL'),
            $this->db->get_results('SELECT score, email FROM p_users ORDER BY id')
        );
        $this->assertSame(['10:x@example.com', '20:NULL', '3:son@example.com'], $rows);
    }

    public function test_batch_update_splits_over_driver_param_limit(): void
    {
        $count = 1200;
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['name' => 'u' . $i, 'score' => 0];
        }
        $this->db->batch_insert('p_users', $rows);

        $updates = [];
        for ($i = 1; $i <= $count; $i++) {
            $updates[] = ['id' => $i, 'score' => $i];
        }

        $updated = 0;
        $queries = $this->captured_updates(function () use (&$updated, $updates): void {
            $updated = $this->db->batch_update('p_users', $updates);
        });

        $this->assertGreaterThan(1, count($queries));
        $this->assertLessThan(10, count($queries));
        $this->assertSame($count, $updated);
        $this->assertSame($count * ($count + 1) / 2, (int) $this->db->get_var('SELECT SUM(score) FROM p_users'));
    }

    public function test_batch_update_rejects_row_without_key_before_writing(): void
    {
        $this->db->batch_insert('p_users', [['name' => 'a', 'score' => 1], ['name' => 'b', 'score' => 2]]);

        try {
            $this->db->batch_update('p_users', [['id' => 1, 'score' => 100], ['score' => 200]]);
            $this->fail('Anahtarsız satır QueryException vermeli');
        } catch (\nsql\database\exceptions\QueryException $e) {
            $this->assertStringContainsString("'id'", $e->getMessage());
        }

        $scores = array_map(fn ($r) => (int) $r->score, $this->db->get_results('SELECT score FROM p_users ORDER BY id'));
        $this->assertSame([1, 2], $scores, 'Hiçbir satır yazılmamalı');
    }

    public function test_unicode_round_trip(): void
    {
        $this->db->insert('INSERT INTO p_users (name) VALUES (?)', ['Şükrü Çağlar ğüöı']);

        $this->assertSame('Şükrü Çağlar ğüöı', $this->db->get_row('SELECT name FROM p_users')->name);
    }

    public function test_query_error_is_reported_and_thrown_when_enabled(): void
    {
        $this->db->set_throw_on_error(false);
        $this->assertSame([], $this->db->get_results('SELECT * FROM p_missing_table'));
        $this->assertNotNull($this->db->get_last_error());

        $this->db->set_throw_on_error(true);
        $this->expectException(QueryException::class);
        $this->db->get_results('SELECT * FROM p_missing_table');
    }

    public function test_quote_identifier_uses_driver_quote(): void
    {
        $expected = self::driver() === 'mysql' ? '`p_users`' : '"p_users"';

        $this->assertSame($expected, $this->db->quote_identifier('p_users'));
        $this->db->insert('INSERT INTO ' . $this->db->quote_identifier('p_users') . ' (name) VALUES (?)', ['q']);
        $this->assertSame(1, (int) $this->db->get_row('SELECT COUNT(*) AS c FROM ' . $this->db->quote_identifier('p_users'))->c);
    }
}
