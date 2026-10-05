<?php

namespace Tests\Integration;

use nsql\database\exceptions\QueryException;
use nsql\database\nsql;
use Tests\Support\DatabaseTestCase;

/**
 * #50: transaction(callable), savepoint, deadlock retry, inTransaction kontrolleri.
 */
class TransactionCallableIntegrationTest extends DatabaseTestCase
{
    private function names(): array
    {
        return array_map(
            fn ($r) => $r->name,
            $this->db->get_results('SELECT name FROM test_table ORDER BY id')
        );
    }

    public function test_commits_and_returns_value(): void
    {
        $result = $this->db->transaction(function (nsql $db) {
            $db->insert('INSERT INTO test_table (name) VALUES (:n)', ['n' => 'a']);

            return 42;
        });

        $this->assertSame(42, $result);
        $this->assertSame(['a'], $this->names());
        $this->assertSame(0, $this->db->get_transaction_level());
    }

    public function test_exception_rolls_back_and_rethrows(): void
    {
        try {
            $this->db->transaction(function (nsql $db) {
                $db->insert('INSERT INTO test_table (name) VALUES (:n)', ['n' => 'a']);

                throw new \DomainException('iptal');
            });
            $this->fail('Exception yeniden fırlatılmalı');
        } catch (\DomainException $e) {
            $this->assertSame('iptal', $e->getMessage());
        }

        $this->assertSame([], $this->names());
        $this->assertSame(0, $this->db->get_transaction_level());
    }

    public function test_nested_transaction_uses_savepoint(): void
    {
        $this->db->transaction(function (nsql $db) {
            $db->insert('INSERT INTO test_table (name) VALUES (:n)', ['n' => 'outer']);

            try {
                $db->transaction(function (nsql $db) {
                    $this->assertSame(2, $db->get_transaction_level());
                    $db->insert('INSERT INTO test_table (name) VALUES (:n)', ['n' => 'inner']);

                    throw new \RuntimeException('iç hata');
                });
            } catch (\RuntimeException $e) {
            }

            $db->transaction(fn (nsql $db) => $db->insert('INSERT INTO test_table (name) VALUES (:n)', ['n' => 'inner2']));
        });

        $this->assertSame(['outer', 'inner2'], $this->names());
    }

    public function test_silent_query_failure_inside_transaction_rolls_back(): void
    {
        $this->assertFalse($this->db->throw_on_error());

        try {
            $this->db->transaction(function (nsql $db) {
                $db->insert('INSERT INTO test_table (name) VALUES (:n)', ['n' => 'a']);
                $db->update('UPDATE no_such_table SET x = 1');
            });
            $this->fail('QueryException bekleniyordu');
        } catch (QueryException $e) {
        }

        $this->assertSame([], $this->names());
        $this->assertFalse($this->db->throw_on_error(), 'Hata modu geri yüklenmeli');
    }

    public function test_retries_on_deadlock(): void
    {
        $calls = 0;
        $result = $this->db->transaction(function (nsql $db) use (&$calls) {
            $calls++;
            $db->insert('INSERT INTO test_table (name) VALUES (:n)', ['n' => "try{$calls}"]);
            if ($calls === 1) {
                $e = new \PDOException('Deadlock found when trying to get lock');
                $e->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];

                throw new QueryException('deadlock', null, [], 0, $e);
            }

            return 'ok';
        }, 3);

        $this->assertSame('ok', $result);
        $this->assertSame(2, $calls);
        $this->assertSame(['try2'], $this->names());
    }

    public function test_non_retryable_error_is_not_retried(): void
    {
        $calls = 0;
        try {
            $this->db->transaction(function () use (&$calls) {
                $calls++;

                throw new \LogicException('kalıcı');
            }, 5);
        } catch (\LogicException $e) {
        }

        $this->assertSame(1, $calls);
    }

    public function test_commit_after_implicit_commit_does_not_throw(): void
    {
        $this->db->begin();
        $this->db->insert('INSERT INTO test_table (name) VALUES (:n)', ['n' => 'before_ddl']);
        $this->db->query('CREATE TABLE IF NOT EXISTS nsql_tx_ddl_tmp (id INT)');

        $this->assertTrue($this->db->commit());
        $this->assertSame(0, $this->db->get_transaction_level());
        $this->assertFalse($this->db->rollback());
        $this->assertSame(['before_ddl'], $this->names());

        $this->db->query('DROP TABLE IF EXISTS nsql_tx_ddl_tmp');
    }
}
