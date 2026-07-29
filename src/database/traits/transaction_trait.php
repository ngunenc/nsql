<?php

namespace nsql\database\traits;

use PDO;
use RuntimeException;

/**
 * Transaction yönetimi (nested savepoint desteği).
 *
 * Beklenen host özellik: ?PDO $pdo
 */
trait transaction_trait
{
    private int $transaction_level = 0;

    /**
     * Bir veritabanı işlemi başlatır (iç içe çağrılarda SAVEPOINT).
     *
     * @throws RuntimeException PDO bağlantısı yoksa
     */
    public function begin(): void
    {
        $pdo = $this->require_pdo();

        if ($this->transaction_level === 0) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec("SAVEPOINT trans{$this->transaction_level}");
        }

        $this->transaction_level++;
    }

    /**
     * Bir veritabanı işlemini tamamlar ve değişiklikleri kaydeder.
     *
     * @throws RuntimeException PDO bağlantısı yoksa
     */
    public function commit(): bool
    {
        $pdo = $this->require_pdo();

        if ($this->transaction_level === 0) {
            return false;
        }

        $this->transaction_level--;

        if ($this->transaction_level === 0) {
            return $pdo->commit();
        }

        return $pdo->exec("RELEASE SAVEPOINT trans{$this->transaction_level}") !== false;
    }

    /**
     * Bir veritabanı işlemini geri alır.
     *
     * @throws RuntimeException PDO bağlantısı yoksa
     */
    public function rollback(): bool
    {
        $pdo = $this->require_pdo();

        if ($this->transaction_level === 0) {
            return false;
        }

        $this->transaction_level--;

        if ($this->transaction_level === 0) {
            return $pdo->rollBack();
        }

        return $pdo->exec("ROLLBACK TO SAVEPOINT trans{$this->transaction_level}") !== false;
    }

    /**
     * İşlem seviyesini döndürür.
     */
    public function get_transaction_level(): int
    {
        return $this->transaction_level;
    }

    /**
     * Geriye dönük alias'lar.
     */
    public function begin_transaction(): void
    {
        $this->begin();
    }

    public function commit_transaction(): bool
    {
        return $this->commit();
    }

    public function rollback_transaction(): bool
    {
        return $this->rollback();
    }

    /**
     * @throws RuntimeException
     */
    private function require_pdo(): PDO
    {
        if (! isset($this->pdo) || $this->pdo === null) {
            throw new RuntimeException('PDO bağlantısı kurulamadı');
        }

        return $this->pdo;
    }
}
