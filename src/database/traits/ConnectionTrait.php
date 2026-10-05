<?php

namespace nsql\database\traits;

use PDO;
use PDOException;
use RuntimeException;
use nsql\database\Config;
use nsql\database\ConnectionPool;
use nsql\database\exceptions\ConnectionException;
use nsql\database\exceptions\ErrorCodes;

/**
 * Bağlantı yaşam döngüsü: havuz kaydı, bağlantı alma/bırakma, kopan bağlantıyı yenileme.
 */
trait ConnectionTrait
{
    private int $retry_limit = 2;
    private ?PDO $pdo = null;
    private ?string $pool_key = null;
    private float $last_activity_at = 0.0;

    /**
     * Bu örneğin DSN/kullanıcı bilgisine ait havuzu kaydeder.
     */
    private function initialize_pool(): void
    {
        $this->pool_key = ConnectionPool::initialize(
            [
                'dsn' => $this->dsn,
                'username' => (string) $this->user,
                'password' => (string) $this->pass,
                'options' => $this->options,
            ],
            (int) Config::get('min_connections', Config::min_connections),
            (int) Config::get('max_connections', Config::max_connections)
        );
    }

    /**
     * Havuzdan bu örneğe ait fiziksel bağlantıyı alır.
     */
    private function initialize_connection(): void
    {
        try {
            $this->pdo = ConnectionPool::get_connection($this->pool_key);
        } catch (PDOException | RuntimeException $e) {
            $driver_error = $e instanceof PDOException ? $e : $e->getPrevious();
            $detail = $driver_error instanceof PDOException ? $driver_error : $e;
            $this->log_error('Veritabanı bağlantı hatası: ' . $detail->getMessage());

            // Sürücü mesajı (kullanıcı adı, host) yalnızca log'a yazılır; uygulamaya genel mesaj döner.
            throw new ConnectionException(
                $driver_error instanceof PDOException
                    ? ConnectionPool::safe_error_message($driver_error)
                    : $e->getMessage(),
                code: ErrorCodes::CONNECTION_FAILED
            );
        }
    }

    /**
     * Bağlantıyı havuza geri bırakır.
     */
    private function disconnect(): void
    {
        $this->drop_reader();
        if ($this->pdo !== null) {
            ConnectionPool::release_connection($this->pdo);
            $this->pdo = null;
        }
    }

    /**
     * Bağlantının kullanılabilir olduğunu sağlar.
     *
     * Her sorgudan önce ping atılmaz: kopan bağlantı sorgu sırasında 2006/2013 ile yakalanıp
     * yeniden bağlanılır (run_with_reconnect). Ping yalnızca bağlantı CONNECTION_PING_IDLE_SECONDS
     * (varsayılan 30) saniyeden uzun süre boşta kaldıysa atılır; transaction içinde
     * sessiz yeniden bağlanmanın önüne geçmek için transaction başında da bu kontrol yapılır.
     *
     * @throws ConnectionException Transaction sırasında bağlantı koptuysa veya yeniden bağlanılamazsa
     */
    public function ensure_connection(): void
    {
        if ($this->pdo === null) {
            $this->initialize_connection();
            $this->touch_connection();

            return;
        }

        $idle_limit = (int) Config::get('connection_ping_idle_seconds', Config::connection_ping_idle_seconds);
        if ($idle_limit < 0 || (microtime(true) - $this->last_activity_at) < $idle_limit) {
            return;
        }

        try {
            @$this->pdo->query('SELECT 1');
        } catch (PDOException $e) {
            $this->reconnect($e);
        }
        $this->touch_connection();
    }

    /**
     * Bağlantının son kullanım zamanını günceller (idle ping hesabı için).
     */
    private function touch_connection(): void
    {
        $this->last_activity_at = microtime(true);
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
            ConnectionPool::discard_connection($this->pdo);
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
                code: ErrorCodes::CONNECTION_LOST,
                previous: $cause instanceof \Exception ? $cause : null
            );
        }

        $this->initialize_connection();
        $this->touch_connection();
    }

    /**
     * Bağlantı koptuğunu gösteren MySQL hata kodları (server has gone away, lost connection).
     */
    private function is_connection_lost_error(PDOException $e): bool
    {
        return in_array((int) ($e->errorInfo[1] ?? 0), [2006, 2013], true);
    }
}
