<?php

namespace Tests\Integration;

use nsql\database\config;
use nsql\database\exceptions\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DatabaseTestCase;

/**
 * #47: THROW_ON_ERROR ile tek hata modeli.
 */
class ThrowOnErrorIntegrationTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        config::set('throw_on_error', false);
        parent::tearDown();
    }

    /**
     * @return array<string, array{callable(\nsql\database\nsql): mixed}>
     */
    public static function failing_calls(): array
    {
        return [
            'query' => [fn ($db) => $db->query('SELEC broken')],
            'get_row' => [fn ($db) => $db->get_row('SELECT * FROM no_such_table WHERE id = :id', ['id' => 1])],
            'get_results' => [fn ($db) => $db->get_results('SELECT * FROM no_such_table')],
            'insert' => [fn ($db) => $db->insert('INSERT INTO no_such_table (a) VALUES (:a)', ['a' => 1])],
            'update' => [fn ($db) => $db->update('UPDATE no_such_table SET a = 1')],
            'delete' => [fn ($db) => $db->delete('DELETE FROM no_such_table')],
            'get_yield' => [fn ($db) => iterator_to_array($db->get_yield('SELECT * FROM no_such_table'))],
            'get_yield unbuffered' => [fn ($db) => iterator_to_array($db->get_yield('SELECT * FROM no_such_table', [], true))],
            'chunk_by_id' => [fn ($db) => iterator_to_array($db->chunk_by_id('SELECT id FROM no_such_table'))],
            'get_chunk' => [fn ($db) => iterator_to_array($db->get_chunk('SELECT * FROM no_such_table'))],
            'batch_update' => [fn ($db) => $db->batch_update('no_such_table', [['id' => 1, 'a' => 2]])],
        ];
    }

    #[DataProvider('failing_calls')]
    public function test_all_methods_throw_query_exception(callable $call): void
    {
        config::set('throw_on_error', true);

        try {
            $call($this->db);
            $this->fail('QueryException bekleniyordu');
        } catch (QueryException $e) {
            $this->assertNotSame('', $e->getMessage());
            $this->assertInstanceOf(\PDOException::class, $e->getPrevious());
        }
    }

    public function test_legacy_mode_returns_false_or_empty(): void
    {
        $this->assertFalse($this->db->update('UPDATE no_such_table SET a = 1'));
        $this->assertFalse($this->db->delete('DELETE FROM no_such_table'));
        $this->assertFalse($this->db->insert('INSERT INTO no_such_table (a) VALUES (1)'));
        $this->assertSame([], $this->db->get_results('SELECT * FROM no_such_table'));
        $this->assertNull($this->db->get_row('SELECT * FROM no_such_table'));
        $this->assertStringContainsString('no_such_table', (string) $this->db->get_last_error());
    }

    public function test_update_and_delete_return_affected_rows_when_enabled(): void
    {
        $this->db->insert('INSERT INTO test_table (name) VALUES (:n)', ['n' => 'a']);
        $this->db->insert('INSERT INTO test_table (name) VALUES (:n)', ['n' => 'b']);

        $this->db->set_throw_on_error(true);
        $this->assertSame(2, $this->db->update("UPDATE test_table SET name = 'x'"));
        $this->assertSame(0, $this->db->update("UPDATE test_table SET name = 'x' WHERE name = 'none'"));
        $this->assertSame(2, $this->db->delete('DELETE FROM test_table'));

        $this->db->set_throw_on_error(null);
        $this->assertTrue($this->db->update("UPDATE test_table SET name = 'y'"));
    }

    public function test_exception_params_are_masked(): void
    {
        config::set('throw_on_error', true);

        try {
            $this->db->get_row('SELECT * FROM no_such_table WHERE password = :password', ['password' => 'hunter2']);
            $this->fail('QueryException bekleniyordu');
        } catch (QueryException $e) {
            $this->assertStringNotContainsString('hunter2', var_export($e->get_params(), true));
        }
    }

    public function test_safe_execute_throws_generic_exception_when_enabled(): void
    {
        $this->db->set_throw_on_error(true);

        try {
            $this->db->safe_execute(fn () => $this->db->get_results('SELECT * FROM no_such_table'), 'Genel hata');
            $this->fail('RuntimeException bekleniyordu');
        } catch (\RuntimeException $e) {
            $this->assertSame('Genel hata', $e->getMessage());
            $this->assertInstanceOf(QueryException::class, $e->getPrevious());
        }
    }

    public function test_batch_update_rolls_back_on_failed_row(): void
    {
        $id = $this->db->insert('INSERT INTO test_table (name) VALUES (:n)', ['n' => 'orig']);

        try {
            $this->db->batch_update('test_table', [
                ['id' => $id, 'name' => 'changed'],
                ['id' => $id, 'no_such_column' => 'x'],
            ]);
            $this->fail('QueryException bekleniyordu');
        } catch (QueryException $e) {
        }

        $this->assertSame('orig', $this->db->get_row('SELECT name FROM test_table WHERE id = :id', ['id' => $id])->name);
    }
}
