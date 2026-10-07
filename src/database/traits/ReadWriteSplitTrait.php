<?php

namespace nsql\database\traits;

use nsql\database\Config;
use nsql\database\logging\Logger;
use PDOStatement;

/**
 * Okuma sorgularını replica'ya, yazma ve transaction'ı primary'ye yönlendirir.
 *
 * Replica ayrı bir nsql örneğidir (kendi havuzu ve statement cache'i); query cache, olay
 * dinleyicileri, logger ve hata modeli primary'de kalır. Primary'de kalan durumlar:
 * - transaction içindeki tüm sorgular,
 * - kilitli okumalar (FOR UPDATE, FOR SHARE, LOCK IN SHARE MODE),
 * - READ_WRITE_STICKY=true (varsayılan) iken bu örnekte bir yazma yapıldıktan sonraki okumalar
 *   (replikasyon gecikmesinde kendi yazdığını okuyamama sorununa karşı).
 * Replica'ya bağlanılamazsa WARNING loglanır ve primary kullanılır.
 */
trait ReadWriteSplitTrait
{
    /** @var array<string, mixed>|null */
    private ?array $read_config = null;
    private bool $read_config_resolved = false;
    private ?self $reader = null;
    private bool $is_reader = false;
    private bool $sticky_primary = false;

    /**
     * Replica ayarı. Anahtarlar: host (string veya liste; birden fazlaysa rastgele seçilir),
     * port, user, pass, db — verilmeyenler primary'den alınır. null = ayrımı kapat.
     *
     * @param array<string, mixed>|null $config
     */
    public function set_read_replica(?array $config): static
    {
        $this->read_config = $config;
        $this->read_config_resolved = true;
        $this->drop_reader();

        return $this;
    }

    /**
     * Sonraki okumaları primary'ye sabitler (true) veya replica yönlendirmesini yeniden açar (false).
     */
    public function stick_to_primary(bool $enabled = true): static
    {
        $this->sticky_primary = $enabled;

        return $this;
    }

    public function uses_read_replica(): bool
    {
        return $this->resolve_read_config() !== null;
    }

    private function should_use_reader(string $sql): bool
    {
        if ($this->is_reader || $this->sticky_primary || $this->get_transaction_level() > 0) {
            return false;
        }
        if (! self::is_read_query($sql) || $this->resolve_read_config() === null) {
            return false;
        }

        return $this->reader() !== null;
    }

    private static function is_read_query(string $sql): bool
    {
        if (! preg_match('/^\s*(SELECT|WITH|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $sql, $m)) {
            return false;
        }
        // SELECT ... INTO (OUTFILE, @var, PostgreSQL'de yeni tablo) yan etkilidir; literal'de geçen
        // "into" da primary'ye gider (güvenli yön)
        if (preg_match('/\bFOR\s+(UPDATE|SHARE)\b|\bLOCK\s+IN\s+SHARE\s+MODE\b|\bINTO\b/i', $sql)) {
            return false;
        }

        return strtoupper($m[1]) !== 'WITH' || ! preg_match('/\b(INSERT|UPDATE|DELETE|MERGE)\b/i', $sql);
    }

    /**
     * Primary'de çalışan yazma sorgusundan sonra sticky moda geçer.
     */
    private function note_primary_query(string $sql): void
    {
        if ($this->is_reader || self::is_read_query($sql)) {
            return;
        }
        if ((bool) $this->setting('read_write_sticky', Config::read_write_sticky) && $this->resolve_read_config() !== null) {
            $this->sticky_primary = true;
        }
    }

    /**
     * Sorguyu replica'da çalıştırır; hata durumu ve son sorgu bilgisi primary'ye aktarılır.
     */
    private function execute_on_reader(string $sql, array $params, ?int $fetch_mode, mixed ...$fetch_mode_args): PDOStatement|false
    {
        $reader = $this->reader();
        assert($reader !== null);
        $this->sync_reader($reader);

        $this->last_query = $sql;
        $this->last_params = $params;
        try {
            return $reader->execute_query($sql, $params, $fetch_mode, ...$fetch_mode_args);
        } finally {
            $this->last_error = $reader->last_error;
            $this->last_pdo_exception = $reader->last_pdo_exception;
        }
    }

    private function sync_reader(self $reader): void
    {
        $reader->query_listeners = $this->query_listeners;
        $reader->psr_logger = $this->psr_logger;
        $reader->throw_on_error = $this->throw_on_error();
        $reader->debug_mode = $this->debug_mode;
    }

    private function reader(): ?self
    {
        if ($this->reader !== null) {
            return $this->reader;
        }

        $config = $this->resolve_read_config();
        if ($config === null) {
            return null;
        }

        $hosts = (array) ($config['host'] ?? $this->connection_args['host']);
        $host = (string) $hosts[array_rand($hosts)];
        $args = $this->connection_args;

        try {
            $reader = new self(
                host: $host,
                db: (string) ($config['db'] ?? $args['db']),
                user: (string) ($config['user'] ?? $args['user']),
                pass: (string) ($config['pass'] ?? $args['pass']),
                charset: $args['charset'],
                debug: $this->debug_mode,
                driver: $args['driver'],
                port: isset($config['port']) ? (int) $config['port'] : $args['port']
            );
        } catch (\Throwable $e) {
            $this->log_error('Okuma replica\'sına bağlanılamadı; sorgular primary\'de çalışıyor: ' . $e->getMessage(), [], Logger::WARNING);
            $this->read_config = null;

            return null;
        }

        $reader->is_reader = true;
        $reader->query_cache_enabled = false;
        $reader->query_cache_store = null;

        return $this->reader = $reader;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolve_read_config(): ?array
    {
        if ($this->is_reader) {
            return null;
        }
        if ($this->read_config_resolved) {
            return $this->read_config;
        }
        $this->read_config_resolved = true;

        if (! (bool) Config::get('read_write_split', false)) {
            return null;
        }

        $hosts = array_values(array_filter(array_map('trim', explode(',', (string) Config::get('db_read_host', '')))));
        if ($hosts === []) {
            $this->log_error('READ_WRITE_SPLIT=true ama DB_READ_HOST tanımlı değil; okuma/yazma ayrımı kapalı.', [], Logger::WARNING);

            return null;
        }

        $config = ['host' => $hosts];
        foreach (['port' => 'db_read_port', 'user' => 'db_read_user', 'pass' => 'db_read_pass', 'db' => 'db_read_name'] as $key => $config_key) {
            if (Config::has($config_key)) {
                $config[$key] = Config::get($config_key);
            }
        }

        return $this->read_config = $config;
    }

    private function drop_reader(): void
    {
        if ($this->reader !== null) {
            $this->reader->disconnect();
            $this->reader = null;
        }
    }
}
