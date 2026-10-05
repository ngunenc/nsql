<?php

namespace Tests\Integration;

use nsql\database\config;
use nsql\database\nsql;
use Tests\Support\DatabaseTestCase;

/**
 * Query cache açıkken yazma / transaction sonrası okumaların güncel kalması (#29).
 */
class QueryCacheIntegrationTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config::set('query_cache_enabled', true);
        $this->db = new nsql(
            host: config::get('db_host', 'localhost'),
            db: config::get('db_name', 'nsql_test_db'),
            user: config::get('db_user', 'root'),
            pass: config::get('db_pass', '')
        );
    }

    protected function tearDown(): void
    {
        config::set('query_cache_enabled', self::query_cache_suite());
        parent::tearDown();
    }

    private function insert_row(string $name): int
    {
        $id = $this->db->insert('INSERT INTO test_table (name) VALUES (:name)', ['name' => $name]);
        $this->assertIsInt($id);

        return $id;
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        $rows = $this->db->get_results('SELECT name FROM test_table ORDER BY id');

        return array_map(fn ($row) => $row->name, $rows);
    }

    public function test_cache_is_enabled_for_this_suite(): void
    {
        $this->assertTrue($this->db->get_cache_stats()['enabled']);
    }

    public function test_repeated_select_is_served_from_cache(): void
    {
        $this->insert_row('cached');

        $this->names();
        $hits_before = $this->db->get_cache_stats()['hits'];
        $this->names();

        $this->assertSame($hits_before + 1, $this->db->get_cache_stats()['hits']);
    }

    public function test_query_builder_select_is_served_from_cache(): void
    {
        $this->insert_row('qb cached');

        $this->db->table('test_table')->select('name')->get();
        $hits_before = $this->db->get_cache_stats()['hits'];
        $this->db->table('test_table')->select('name')->get();

        $this->assertSame($hits_before + 1, $this->db->get_cache_stats()['hits']);
    }

    public function test_get_results_reflects_update(): void
    {
        $id = $this->insert_row('before');
        $this->assertSame(['before'], $this->names());

        $this->db->update('UPDATE test_table SET name = :name WHERE id = :id', ['name' => 'after', 'id' => $id]);

        $this->assertSame(['after'], $this->names());
    }

    public function test_get_results_reflects_insert_and_delete(): void
    {
        $id = $this->insert_row('first');
        $this->assertSame(['first'], $this->names());

        $this->insert_row('second');
        $this->assertSame(['first', 'second'], $this->names());

        $this->db->delete('DELETE FROM test_table WHERE id = :id', ['id' => $id]);
        $this->assertSame(['second'], $this->names());
    }

    public function test_query_builder_get_reflects_update(): void
    {
        $id = $this->insert_row('qb before');
        $first = $this->db->table('test_table')->select('name')->get();
        $this->assertSame('qb before', $first[0]->name);

        $this->db->update('UPDATE test_table SET name = :name WHERE id = :id', ['name' => 'qb after', 'id' => $id]);

        $second = $this->db->table('test_table')->select('name')->get();
        $this->assertSame('qb after', $second[0]->name);
    }

    public function test_batch_insert_invalidates_cache(): void
    {
        $this->insert_row('one');
        $this->assertSame(['one'], $this->names());

        $this->db->batch_insert('test_table', [['name' => 'two'], ['name' => 'three']]);

        $this->assertSame(['one', 'two', 'three'], $this->names());
    }

    public function test_batch_update_invalidates_cache(): void
    {
        $id = $this->insert_row('old');
        $this->assertSame(['old'], $this->names());

        $this->db->batch_update('test_table', [['id' => $id, 'name' => 'new']]);

        $this->assertSame(['new'], $this->names());
    }

    public function test_raw_query_write_invalidates_cache(): void
    {
        $this->insert_row('to be truncated');
        $this->assertSame(['to be truncated'], $this->names());

        $this->db->query('DELETE FROM test_table');

        $this->assertSame([], $this->names());
    }

    public function test_rollback_does_not_leave_uncommitted_rows_in_cache(): void
    {
        $this->insert_row('committed');

        $this->db->begin();
        $this->insert_row('uncommitted');
        $this->assertSame(['committed', 'uncommitted'], $this->names());
        $this->db->rollback();

        $this->assertSame(['committed'], $this->names());
    }

    public function test_get_row_after_rollback_returns_committed_value(): void
    {
        $id = $this->insert_row('original');
        $this->assertSame('original', $this->db->get_row('SELECT name FROM test_table WHERE id = :id', ['id' => $id])->name);

        $this->db->begin();
        $this->db->update('UPDATE test_table SET name = :name WHERE id = :id', ['name' => 'changed', 'id' => $id]);
        $this->assertSame('changed', $this->db->get_row('SELECT name FROM test_table WHERE id = :id', ['id' => $id])->name);
        $this->db->rollback();

        $this->assertSame('original', $this->db->get_row('SELECT name FROM test_table WHERE id = :id', ['id' => $id])->name);
    }
}
