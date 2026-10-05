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
