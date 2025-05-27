<?php

namespace nsql\database\traits;

trait transaction_trait {
    private int $transactionLevel = 0;

    /**
     * Bir veritabanı işlemi başlatır
     */
    public function begin(): void {
        if ($this->transactionLevel === 0) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec("SAVEPOINT trans{$this->transactionLevel}");
        }
        $this->transactionLevel++;
    }

    /**
     * Bir veritabanı işlemini tamamlar ve değişiklikleri kaydeder
     */
    public function commit(): bool {
        $this->transactionLevel--;
        
        if ($this->transactionLevel === 0) {
            return $this->pdo->commit();
        } else if ($this->transactionLevel > 0) {
            return $this->pdo->exec("RELEASE SAVEPOINT trans{$this->transactionLevel}") !== false;
        }
        
        return false;
    }

    /**
     * Bir veritabanı işlemini geri alır
     */
    public function rollback(): bool {
        if ($this->transactionLevel === 0) {
            return false;
        }

        $this->transactionLevel--;
        
        if ($this->transactionLevel === 0) {
            return $this->pdo->rollBack();
        } else {
            return $this->pdo->exec("ROLLBACK TO SAVEPOINT trans{$this->transactionLevel}") !== false;
        }
    }

    /**
     * İşlem seviyesini döndürür
     */
    public function getTransactionLevel(): int {
        return $this->transactionLevel;
    }
}
