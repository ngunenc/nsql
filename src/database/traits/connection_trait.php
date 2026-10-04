<?php

namespace nsql\database\traits;

use PDO;
use PDOException;
use RuntimeException;
use nsql\database\config;
use nsql\database\connection_pool;
use nsql\database\exceptions\ConnectionException;
use nsql\database\exceptions\error_codes;

/**
 * Bağlantı yaşam döngüsü: havuz kaydı, bağlantı alma/bırakma, kopan bağlantıyı yenileme.
 */
trait connection_trait
{
    private int $retry_limit = 2;
    private ?PDO $pdo = null;
    private ?string $pool_key = null;

    /**
     * Bu örneğin DSN/kullanıcı bilgisine ait havuzu kaydeder.
     */
    private function initialize_pool(): void
    {
        $this->pool_key = connection_pool::initialize(
            [
                'dsn' => $this->dsn,
                'username' => (string) $this->user,
                'password' => (string) $this->pass,
                'options' => $this->options,
            ],
            (int) config::get('min_connections', config::min_connections),
            (int) config::get('max_connections', config::max_connections)
        );
    }

    /**
     * Havuzdan bu örneğe ait fiziksel bağlantıyı alır.
     */
    private function initialize_connection(): void
    {
        try {
            $this->pdo = connection_pool::get_connection($this->pool_key);
        } catch (PDOException | RuntimeException $e) {
            $this->log_error('Veritabanı bağlantı hatası: ' . $e->getMessage());

            throw new ConnectionException(
                'Veritabanı bağlantı hatası: ' . $e->getMessage(),
                code: error_codes::CONNECTION_FAILED,
                previous: $e
            );
        }
    }

    /**
     * Bağlantıyı havuza geri bırakır.
     */
    private function disconnect(): void
    {
        if ($this->pdo !== null) {
            connection_pool::release_connection($this->pdo);
            $this->pdo = null;
        }
    }

    /**
     * Bağlantının canlı olduğunu doğrular; kopmuşsa yeniden bağlanır.
     *
     * @throws ConnectionException Transaction sırasında bağlantı koptuysa veya yeniden bağlanılamazsa
     */
    public function ensure_connection(): void
    {
        if ($this->pdo === null) {
            $this->initialize_connection();

            return;
        }

        try {
            @$this->pdo->query('SELECT 1');
        } catch (PDOException $e) {
            $this->reconnect($e);
        }
    }

    /**
     * Mevcut bağlantıyı havuzdan atar ve yeni bir bağlantı alır.
     *
     * Eski bağlantıya ait prepared statement'lar geçersiz olduğundan statement cache temizlenir.
     * Açık transaction varsa sessizce yeniden bağlanmak veri tutarlılığını bozacağından
     * transaction durumu sıfırlanır ve exception fırlatılır.
     *
     * @throws ConnectionException
     */
    public function reconnect(?\Throwable $cause = null): void
    {
        $in_transaction = $this->get_transaction_level() > 0;

        if ($this->pdo !== null) {
            connection_pool::discard_connection($this->pdo);
            $this->pdo = null;
        }
        $this->clear_statement_cache();

        if ($in_transaction) {
            $this->reset_transaction_state();
            $this->log_error('Transaction sırasında veritabanı bağlantısı koptu', [
                'cause' => $cause?->getMessage(),
            ]);

            throw new ConnectionException(
                'Transaction sırasında veritabanı bağlantısı koptu; transaction geri alındı. '
                . 'İşlemi baştan tekrarlayın.',
                code: error_codes::CONNECTION_LOST,
                previous: $cause instanceof \Exception ? $cause : null
            );
        }

        $this->initialize_connection();
    }

    /**
     * Bağlantı koptuğunu gösteren MySQL hata kodları (server has gone away, lost connection).
     */
    private function is_connection_lost_error(PDOException $e): bool
    {
        return in_array((int) ($e->errorInfo[1] ?? 0), [2006, 2013], true);
    }
}
