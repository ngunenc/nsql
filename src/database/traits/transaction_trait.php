<?php

namespace nsql\database\traits;

trait transaction_trait
{
    private int $transaction_level = 0;

    /**
     * Bir veritabanı işlemi başlatır
     */
    public function begin(): void
    {
        if ($this->transaction_level === 0) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec("SAVEPOINT trans{$this->transaction_level}");
        }
        $this->transaction_level++;
    }

    /**
     * Bir veritabanı işlemini tamamlar ve değişiklikleri kaydeder
     */
    public function commit(): bool
    {
        $this->transaction_level--;

        if ($this->transaction_level === 0) {
            return $this->pdo->commit();
        } elseif ($this->transaction_level > 0) {
            return $this->pdo->exec("RELEASE SAVEPOINT trans{$this->transaction_level}") !== false;
        }

        return false;
    }

    /**
     * Bir veritabanı işlemini geri alır
     */
    public function rollback(): bool
    {
        if ($this->transaction_level === 0) {
            return false;
        }

        $this->transaction_level--;

        if ($this->transaction_level === 0) {
            return $this->pdo->rollBack();
        } else {
            return $this->pdo->exec("ROLLBACK TO SAVEPOINT trans{$this->transaction_level}") !== false;
        }
    }

    /**
     * İşlem seviyesini döndürür
     */
    public function get_transaction_level(): int
    {
        return $this->transaction_level;
    }
}
