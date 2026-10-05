<?php

namespace Tests\Portable;

use Tests\Support\PortableTestCase;

class TransactionPortableTest extends PortableTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->create_table('p_accounts', ['owner VARCHAR(20) NOT NULL', 'balance INT NOT NULL']);
        $this->db->insert('INSERT INTO p_accounts (owner, balance) VALUES (?, ?), (?, ?)', ['a', 100, 'b', 0]);
    }

    private function balances(): array
    {
        return array_map(fn ($r) => (int) $r->balance, $this->db->get_results('SELECT balance FROM p_accounts ORDER BY id'));
    }

    public function test_commit_and_rollback(): void
    {
        $this->db->begin();
        $this->db->update('UPDATE p_accounts SET balance = balance - 30 WHERE owner = ?', ['a']);
        $this->db->commit();

        $this->db->begin();
        $this->db->update('UPDATE p_accounts SET balance = 0 WHERE owner = ?', ['a']);
        $this->db->rollback();

        $this->assertSame([70, 0], $this->balances());
    }

    public function test_nested_transaction_uses_savepoints(): void
    {
        $this->db->begin();
        $this->db->update('UPDATE p_accounts SET balance = 1 WHERE owner = ?', ['a']);

        $this->db->begin();
        $this->db->update('UPDATE p_accounts SET balance = 2 WHERE owner = ?', ['b']);
        $this->db->rollback();

        $this->assertSame(1, $this->db->get_transaction_level());
        $this->db->commit();

        $this->assertSame([1, 0], $this->balances());
    }

    public function test_transaction_callable_commits_and_rolls_back(): void
    {
        $result = $this->db->transaction(function ($db) {
            $db->update('UPDATE p_accounts SET balance = balance - 40 WHERE owner = ?', ['a']);
            $db->update('UPDATE p_accounts SET balance = balance + 40 WHERE owner = ?', ['b']);

            return 'ok';
        });
        $this->assertSame('ok', $result);

        try {
            $this->db->transaction(function ($db) {
                $db->update('UPDATE p_accounts SET balance = 0');
                throw new \RuntimeException('iptal');
            });
            $this->fail('exception bekleniyordu');
        } catch (\RuntimeException $e) {
            $this->assertSame('iptal', $e->getMessage());
        }

        $this->assertSame([60, 40], $this->balances());
        $this->assertSame(0, $this->db->get_transaction_level());
    }
}
