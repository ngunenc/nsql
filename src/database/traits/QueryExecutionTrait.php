<?php

namespace nsql\database\traits;

use PDOException;
use PDOStatement;

/**
 * Sorgu yürütme hattı: bağlantı doğrulama, statement cache, parametre bağlama, reconnect retry.
 */
trait QueryExecutionTrait
{
    /** true iken statement cache atlanır (statement çağırana veya tembel okumaya veriliyor) */
    private bool $bypass_statement_cache = false;

    /**
     * Statement cache'i atlayarak sorgu çalıştırır.
     *
     * Dönen statement çağırana veriliyor veya satırları tembel okunuyorsa kullanılır: cache'teki
     * statement aynı SQL ile yeniden çalıştırılınca açık imleç baştan başlar (#96).
     */
    private function execute_query_uncached(string $sql, array $params = [], ?int $fetch_mode = null, mixed ...$fetch_mode_args): PDOStatement|false
    {
        $previous = $this->bypass_statement_cache;
        $this->bypass_statement_cache = true;

        try {
            return $this->execute_query($sql, $params, $fetch_mode, ...$fetch_mode_args);
        } finally {
            $this->bypass_statement_cache = $previous;
        }
    }

    /**
     * Sorguyu çalıştırır: replica yönlendirmesi, bağlantı kontrolü, parametre doğrulama, reconnect ile yürütme
     */
    private function execute_query(string $sql, array $params = [], ?int $fetch_mode = null, mixed ...$fetch_mode_args): PDOStatement|false
    {
        $this->set_last_called_method();

        if ($this->streaming) {
            throw new \RuntimeException(
                'Unbuffered get_yield() akışı sürerken aynı bağlantıda sorgu çalıştırılamaz. '
                . 'Döngü içinde sorgu gerekiyorsa chunk_by_id() kullanın, ayrı bir nsql örneği açın '
                . 'veya get_yield(..., unbuffered: false) çağırın.'
            );
        }

        if ($this->should_use_reader($sql)) {
            return $this->execute_on_reader($sql, $params, $fetch_mode, ...$fetch_mode_args);
        }

        $this->ensure_connection();

        // PDO bağlantısı kontrolü
        if (!$this->validate_pdo_connection()) {
            return false;
        }

        // Sorgu bilgilerini kaydet
        $this->prepare_query_context($sql, $params);

        // Parametreleri validate et
        $this->validate_param_types($params);
        $this->last_pdo_exception = null;

        $stmt = $this->run_with_reconnect($sql, $params, $fetch_mode, ...$fetch_mode_args);
        if ($stmt === false && $this->throw_on_error()) {
            throw $this->make_query_exception($sql, $params);
        }
        if ($stmt !== false) {
            $this->note_primary_query($sql);
        }

        return $stmt;
    }

    /**
     * Statement'ı hazırlar (veya cache'den alır), parametreleri bağlar ve fetch mode'u ayarlar.
     */
    private function prepare_bound_statement(string $sql, array $params, ?int $fetch_mode, mixed ...$fetch_mode_args): PDOStatement|false
    {
        $stmt = $this->prepare_or_get_cached_statement($sql, $params);
        if ($stmt === false) {
            return false;
        }

        $this->bind_parameters($stmt, $params);

        if ($fetch_mode !== null) {
            $stmt->setFetchMode($fetch_mode, ...$fetch_mode_args);
        }

        return $stmt;
    }

    /**
     * PDO bağlantısının varlığını doğrular
     */
    private function validate_pdo_connection(): bool
    {
        if ($this->pdo === null) {
            $this->last_error = 'PDO bağlantısı kurulamadı';
            $this->log_error($this->last_error);
            return false;
        }
        return true;
    }

    /**
     * Son sorgu bilgisini (debug / hata raporu için) kaydeder
     */
    private function prepare_query_context(string $sql, array $params): void
    {
        $this->last_query = $sql;
        $this->last_params = $params;
        $this->last_error = null;
    }

    /**
     * Statement'ı hazırlar veya cache'den alır
     */
    private function prepare_or_get_cached_statement(string $sql, array $params): PDOStatement|false
    {
        $cache_key = $this->bypass_statement_cache ? null : $this->get_statement_cache_key($sql, $params);

        $stmt = $cache_key !== null ? $this->get_from_statement_cache($cache_key) : null;
        if ($stmt !== null) {
            return $stmt;
        }

        try {
            $stmt = $this->require_pdo()->prepare($sql);
        } catch (PDOException $e) {
            $this->handle_prepare_error($e);
            return false;
        }
        if ($stmt === false) {
            return false;
        }
        if ($cache_key !== null) {
            $this->add_to_statement_cache($cache_key, $stmt);
        }

        return $stmt;
    }

    /**
     * Parametreleri statement'a bağlar
     */
    private function bind_parameters(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $param) {
            $param_name = $this->normalize_parameter_name($key);

            if (is_array($param) && array_key_exists('value', $param) && isset($param['type'])) {
                // Query Builder'dan gelen yapılandırılmış parametre
                $stmt->bindValue($param_name, $param['value'], $param['type']);
            } else {
                // Doğrudan değer olarak gelen parametre
                $param_type = $this->determine_param_type($param);
                $stmt->bindValue($param_name, $param, $param_type);
            }
        }
    }

    /**
     * Sorguyu çalıştırır; bağlantı koptuysa yeniden bağlanıp statement'ı yeni bağlantıda tekrar hazırlar.
     */
    private function run_with_reconnect(string $sql, array $params, ?int $fetch_mode, mixed ...$fetch_mode_args): PDOStatement|false
    {
        $attempts = 0;
        $started = hrtime(true);

        while (true) {
            $stmt = $this->prepare_bound_statement($sql, $params, $fetch_mode, ...$fetch_mode_args);
            if ($stmt === false) {
                $this->dispatch_query_event($sql, $params, $started, null, $this->last_pdo_exception);

                return false;
            }

            try {
                @$stmt->execute();
                $this->touch_connection();
                $this->dispatch_query_event($sql, $params, $started, $stmt, null);

                return $stmt;
            } catch (PDOException $e) {
                $attempts++;
                $this->handle_execution_error($e);

                if (! $this->should_retry($e, $attempts, $sql)) {
                    $this->dispatch_query_event($sql, $params, $started, null, $e);
                    if (self::is_connection_lost_error($e)) {
                        // Kopuk bağlantı sonraki çağrılara dağıtılmaz; yazma ise tekrar çalıştırılmaz
                        $this->reconnect($e);
                    }

                    return false;
                }

                // Eski bağlantının statement'ları geçersiz; transaction içindeyse exception fırlatır
                $this->reconnect($e);
            }
        }
    }

    /**
     * Prepare hatasını kaydeder
     */
    private function handle_prepare_error(PDOException $e): void
    {
        $this->last_pdo_exception = $e;
        $this->last_error = $e->getMessage();
        $this->log_error($this->last_error);
        $this->last_results = [];
    }

    /**
     * Yürütme hatasını kaydeder
     */
    private function handle_execution_error(PDOException $e): void
    {
        $this->last_pdo_exception = $e;
        $this->last_error = $e->getMessage();
        $this->log_error($this->last_error);
        $this->last_results = [];
    }

    /**
     * Bağlantı kopmasında yalnızca okuma sorguları tekrar denenir: yazma sorgusu sunucuda
     * çalışıp commit edilmiş olabilir (ör. MySQL 2013), tekrar çalıştırmak çift kayıt üretir.
     */
    private function should_retry(PDOException $e, int $attempts, string $sql): bool
    {
        return self::is_connection_lost_error($e) && $attempts <= $this->retry_limit && self::is_read_query($sql);
    }
}
