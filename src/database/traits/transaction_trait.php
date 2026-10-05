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
        if ($this->transaction_level === 0 && method_exists($this, 'ensure_connection')) {
            $this->ensure_connection();
        }

        $pdo = $this->require_pdo();

        if ($this->transaction_level === 0) {
            try {
                $pdo->beginTransaction();
            } catch (\PDOException $e) {
                if (! method_exists($this, 'is_connection_lost_error') || ! $this->is_connection_lost_error($e)) {
                    throw $e;
                }
                $this->reconnect($e);
                $this->require_pdo()->beginTransaction();
            }
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

        // DDL (CREATE/ALTER/DROP …) MySQL'de implicit commit yapar; sunucuda açık transaction kalmaz
        if (! $pdo->inTransaction()) {
            $this->transaction_level = 0;
            $this->flush_deferred_cache_invalidations(true);

            return true;
        }

        $this->transaction_level--;

        if ($this->transaction_level === 0) {
            $committed = $pdo->commit();
            $this->flush_deferred_cache_invalidations($committed);

            return $committed;
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

        if (! $pdo->inTransaction()) {
            $this->transaction_level = 0;
            $this->flush_deferred_cache_invalidations(true);

            return false;
        }

        $this->transaction_level--;

        if ($this->transaction_level === 0) {
            $this->flush_deferred_cache_invalidations(false);

            return $pdo->rollBack();
        }

        return $pdo->exec("ROLLBACK TO SAVEPOINT trans{$this->transaction_level}") !== false;
    }

    /**
     * Callable'ı transaction içinde çalıştırır: başarıda commit, exception'da rollback + yeniden fırlatma.
     *
     * - İç içe çağrılar SAVEPOINT kullanır; iç hata yalnızca kendi savepoint'ine geri döner.
     * - Callable içinde sorgu hataları her zaman exception'dır (THROW_ON_ERROR geçici olarak açılır);
     *   sessiz `false` dönüşüyle yarım işlemin commit edilmesi önlenir.
     * - Deadlock (1213, PostgreSQL 40P01), lock wait timeout (1205) ve SQLSTATE 40001'de en dış seviyede işlem baştan
     *   tekrarlanır. Deneme sayısı: $attempts ?? TRANSACTION_RETRY_ATTEMPTS (varsayılan 1 = tekrar yok).
     *   Callable tekrar çalışabileceği için yan etkisiz (idempotent) olmalıdır.
     *
     * @template T
     * @param callable(static): T $fn
     * @return T
     */
    public function transaction(callable $fn, ?int $attempts = null): mixed
    {
        $attempts = max(1, $attempts ?? (int) \nsql\database\config::get('transaction_retry_attempts', \nsql\database\config::transaction_retry_attempts));
        $outer_level = $this->transaction_level;
        $previous_mode = $this->throw_on_error;
        $this->throw_on_error = true;

        try {
            for ($attempt = 1;; $attempt++) {
                $this->begin();

                try {
                    $result = $fn($this);
                    if ($this->transaction_level > $outer_level) {
                        $this->commit();
                    }

                    return $result;
                } catch (\Throwable $e) {
                    if ($this->transaction_level > $outer_level) {
                        try {
                            $this->rollback();
                        } catch (\Throwable) {
                            // Deadlock sunucuda transaction'ı zaten geri almış olabilir (savepoint yok)
                            $this->transaction_level = $outer_level;
                        }
                    }

                    if ($outer_level > 0 || $attempt >= $attempts || ! self::is_retryable_transaction_error($e)) {
                        throw $e;
                    }

                    usleep(min(1000000, 50000 * (2 ** ($attempt - 1))));
                }
            }
        } finally {
            $this->throw_on_error = $previous_mode;
        }
    }

    /**
     * Deadlock / lock wait timeout / serialization failure mı? (exception zincirine bakılır)
     */
    private static function is_retryable_transaction_error(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof \PDOException) {
                $driver_code = (int) ($current->errorInfo[1] ?? 0);
                $sql_state = (string) ($current->errorInfo[0] ?? $current->getCode());
                if (in_array($driver_code, [1213, 1205], true) || in_array($sql_state, ['40001', '40P01'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * İşlem seviyesini döndürür.
     */
    public function get_transaction_level(): int
    {
        return $this->transaction_level;
    }

    /**
     * Bağlantı kaybında sunucu tarafı transaction zaten sonlanmıştır; yerel sayaç sıfırlanır.
     */
    private function reset_transaction_state(): void
    {
        $this->flush_deferred_cache_invalidations(false);
        $this->transaction_level = 0;
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
