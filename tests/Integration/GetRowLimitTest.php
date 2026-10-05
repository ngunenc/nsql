<?php

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

/**
 * #34: get_row() LIMIT ekleme mantığı; QueryBuilder::first() ve Model::find().
 */
class GetRowLimitTest extends DatabaseTestCase
{
    private function seed(): array
    {
        $ids = [];
        foreach (['alpha', 'beta', 'gamma'] as $name) {
            $ids[$name] = $this->db->insert('INSERT INTO test_table (name) VALUES (:name)', ['name' => $name]);
        }

        return $ids;
    }

    public function test_query_builder_first_works(): void
    {
        $ids = $this->seed();

        $row = $this->db->table('test_table')->where('id', '=', $ids['beta'])->first();

        $this->assertNotNull($row);
        $this->assertSame('beta', $row->name);
    }

    public function test_query_builder_first_with_order_by(): void
    {
        $this->seed();

        $row = $this->db->table('test_table')->order_by('id', 'DESC')->first();

        $this->assertSame('gamma', $row->name);
    }

    public function test_model_find_works(): void
    {
        $ids = $this->seed();

        $model = TestTableModel::find($ids['alpha'], $this->db);

        $this->assertNotNull($model);
        $this->assertSame('alpha', $model->name);
        $this->assertNull(TestTableModel::find(999999, $this->db));
    }

    public function test_get_row_with_for_update(): void
    {
        $ids = $this->seed();

        $this->db->begin();
        $row = $this->db->get_row('SELECT name FROM test_table WHERE id = :id FOR UPDATE', ['id' => $ids['gamma']]);
        $this->db->commit();

        $this->assertSame('gamma', $row->name);
    }

    public function test_get_row_with_lock_in_share_mode(): void
    {
        $ids = $this->seed();

        $row = $this->db->get_row('SELECT name FROM test_table WHERE id = :id LOCK IN SHARE MODE', ['id' => $ids['alpha']]);

        $this->assertSame('alpha', $row->name);
    }

    public function test_get_row_with_trailing_semicolon(): void
    {
        $ids = $this->seed();

        $row = $this->db->get_row('SELECT name FROM test_table WHERE id = :id;', ['id' => $ids['beta']]);

        $this->assertSame('beta', $row->name);
    }

    public function test_get_row_with_placeholder_limit(): void
    {
        $this->seed();

        $positional = $this->db->get_row('SELECT name FROM test_table ORDER BY id LIMIT ?', [1]);
        $named = $this->db->get_row('SELECT name FROM test_table ORDER BY id LIMIT :lim', ['lim' => 1]);
        $numeric = $this->db->get_row('SELECT name FROM test_table ORDER BY id LIMIT 1 OFFSET 1');

        $this->assertSame('alpha', $positional->name);
        $this->assertSame('alpha', $named->name);
        $this->assertSame('beta', $numeric->name);
    }

    public function test_get_row_returns_first_row_without_limit(): void
    {
        $this->seed();

        $row = $this->db->get_row('SELECT name FROM test_table ORDER BY id');

        $this->assertSame('alpha', $row->name);
        $this->assertCount(3, $this->db->get_results('SELECT id FROM test_table'));
    }
}
