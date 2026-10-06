<?php

namespace nsql\database\traits;

use nsql\database\Config;
use nsql\database\optimization\MemoryMonitor;
use PDO;
use PDOException;
use PDOStatement;

trait StreamingTrait
{
    private bool $streaming = false;

    /**
     * Büyük veri setlerini satır satır döndürür (Generator)
     *
     * Unbuffered modda (2.0 varsayılanı, YIELD_UNBUFFERED=true) sorgu tek seferde çalışır ve
     * satırlar sunucudan okundukça döner: sabit bellek, OFFSET yok. Akış sürerken aynı nsql
     * örneğinde başka sorgu çalıştırılamaz (MySQL kısıtı); döngü içinde yazma gerekiyorsa
     * chunk_by_id() kullanın. YIELD_UNBUFFERED=false veya $unbuffered=false: LIMIT/OFFSET ile
     * parça parça okuma (1.x davranışı; sorgu kendi LIMIT/OFFSET'ini içeremez).
     *
     * @param string $query SQL sorgusu
     * @param array $params Sorgu parametreleri
     * @param bool|null $unbuffered null = YIELD_UNBUFFERED ayarı
     * @return \Generator<int, object>
     */
    public function get_yield(string $query, array $params = [], ?bool $unbuffered = null): \Generator
    {
        $this->set_last_called_method();

        if ($unbuffered ?? (bool) Config::get('yield_unbuffered', Config::yield_unbuffered)) {
            yield from $this->stream_query($query, $params);

            return;
        }

        if ($this->has_top_level_limit($query)) {
            throw new \InvalidArgumentException('get_yield() metodu LIMIT veya OFFSET içeren sorgularla kullanılamaz.');
        }

        $offset = 0;
        $chunk_size = (int) Config::get('default_chunk_size', Config::default_chunk_size);
        $total_rows = 0;
        $stmt = null;

        // PDO bağlantısı kontrolü
        if ($this->pdo === null) {
            $this->ensure_connection();
            if ($this->pdo === null) {
                return;
            }
        }

        try {
            while (true) {
                $this->check_memory_status();
                $this->adjust_chunk_size();

                // Önceki statement'ı temizle (memory leak önleme)
                if ($stmt !== null) {
                    $stmt->closeCursor();
                    $stmt = null;
                }

                // Chunk sorgusu oluştur ve çalıştır
                $chunk_query = $query . " LIMIT " . $chunk_size . " OFFSET " . $offset;
                $stmt = $this->execute_query($chunk_query, $params);

                if ($stmt === false) {
                    break;
                }

                // Satırları yield et
                $found_rows = false;
                while ($row = $stmt->fetch(PDO::FETCH_OBJ)) {
                    $found_rows = true;
                    $total_rows++;

                    // Memory optimizasyonu (config'den al)
                    $cleanup_interval = Config::get('generator_cleanup_interval', 1000);
                    if ($total_rows % $cleanup_interval === 0) {
                        $this->cleanup_resources();
                    }

                    yield $row;
                }

                // Tüm satırlar okunduysa çık
                if (! $found_rows) {
                    break;
                }

                $offset += $chunk_size;

                // Maksimum limit kontrolü
                if ($offset >= $this->max_result_set_size()) {
                    throw new \RuntimeException(
                        sprintf(
                            'Maksimum sonuç kümesi boyutu aşıldı! (Limit: %d)',
                            $this->max_result_set_size()
                        )
                    );
                }

                // Her chunk'tan sonra GC çağır (daha agresif cleanup)
                $gc_interval_multiplier = Config::get('generator_gc_interval_multiplier', 5);
                if ($offset % (Config::default_chunk_size * $gc_interval_multiplier) === 0) {
                    gc_collect_cycles();
                }
            }
        } finally {
            // Explicit cleanup: Statement'ı temizle
            if ($stmt !== null) {
                $stmt->closeCursor();
                $stmt = null;
            }

            // Final GC çağrısı
            gc_collect_cycles();
        }
    }

    /**
     * Sorgunun en dış seviyesinde LIMIT/OFFSET var mı? Subquery ve string literal'ler yok sayılır.
     */
    private function has_top_level_limit(string $query): bool
    {
        $stripped = (string) preg_replace('/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|`[^`]*`/s', "''", $query);
        do {
            $stripped = (string) preg_replace('/\([^()]*\)/', ' ', $stripped, -1, $count);
        } while ($count > 0);

        return preg_match('/\b(LIMIT|OFFSET)\b/i', $stripped) === 1;
    }

    /**
     * Sorguyu tek seferde çalıştırıp satırları sunucudan okundukça döndürür (MySQL'de unbuffered).
     *
     * @return \Generator<int, object>
     */
    private function stream_query(string $query, array $params): \Generator
    {
        if ($this->streaming) {
            throw new \RuntimeException('Bu bağlantıda zaten aktif bir get_yield() akışı var.');
        }

        if ($this->should_use_reader($query)) {
            $reader = $this->reader();
            assert($reader !== null);
            $this->sync_reader($reader);
            try {
                yield from $reader->stream_query($query, $params);
            } finally {
                $this->last_error = $reader->last_error;
            }

            return;
        }

        $this->ensure_connection();
        $pdo = $this->pdo;
        if ($pdo === null) {
            $this->validate_pdo_connection();

            return;
        }

        $is_mysql = $this->get_driver_name() === 'mysql';
        $previous_buffered = $is_mysql ? $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) : null;

        $this->prepare_query_context($query, $params);
        $this->validate_param_types($params);

        $stmt = null;
        try {
            if ($is_mysql) {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            }

            // Statement cache'e alınmaz: unbuffered cursor kapanmadan aynı statement yeniden kullanılamaz
            $started = hrtime(true);
            try {
                $stmt = $pdo->prepare($query);
                if ($stmt === false) {
                    return;
                }
                $this->bind_parameters($stmt, $params);
                $stmt->execute();
                $this->touch_connection();
                $this->dispatch_query_event($query, $params, $started, $stmt, null, row_count_known: false);
            } catch (PDOException $e) {
                $this->handle_execution_error($e);
                $this->dispatch_query_event($query, $params, $started, null, $e);
                if ($this->throw_on_error()) {
                    throw $this->make_query_exception($query, $params);
                }

                return;
            }

            $this->streaming = true;
            $cleanup_interval = max(1, (int) Config::get('generator_cleanup_interval', 1000));
            $rows = 0;

            while (($row = $stmt->fetch(PDO::FETCH_OBJ)) !== false) {
                if (++$rows % $cleanup_interval === 0) {
                    $this->check_memory_status();
                }

                yield $row;
            }
        } finally {
            $this->streaming = false;
            if ($stmt instanceof PDOStatement) {
                $stmt->closeCursor();
            }
            $stmt = null;
            if ($is_mysql && $this->pdo === $pdo) {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $previous_buffered);
            }
        }
    }

    /**
     * Keyset (seek) tabanlı parça parça okuma: OFFSET kullanmaz, satır atlamaz/tekrarlamaz.
     *
     * Sorgu türetilmiş tablo olarak sarılır:
     * SELECT * FROM (<sorgu>) nsql_chunk WHERE <kolon> > :son ORDER BY <kolon> LIMIT <boyut>
     * Kolon sonuçta benzersiz ve sıralanabilir olmalıdır (genellikle birincil anahtar).
     * Her parça ayrı sorgu olduğundan döngü içinde aynı bağlantıda yazma yapılabilir.
     *
     * @param string $query SQL sorgusu (kendi ORDER BY / LIMIT'i olmamalı)
     * @param array $params Sorgu parametreleri (named veya positional)
     * @param string $column Keyset kolonu (sonuç kümesindeki ad)
     * @param int $size Parça boyutu
     * @return \Generator<int, list<object>>
     */
    public function chunk_by_id(string $query, array $params = [], string $column = 'id', int $size = 1000): \Generator
    {
        $this->set_last_called_method();

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
            throw new \InvalidArgumentException("Geçersiz keyset kolonu: {$column}");
        }
        if ($size < 1) {
            throw new \InvalidArgumentException('Parça boyutu en az 1 olmalıdır.');
        }

        $quoted = $this->get_driver_name() === 'mysql' ? "`{$column}`" : "\"{$column}\"";
        $positional = $params !== [] && array_is_list($params);
        $placeholder = $positional ? '?' : ':nsql_chunk_last';
        // Satır sonları: sorgu `-- yorum` ile biterse sarmalayıcının devamı yorumda kalmasın
        $base = "SELECT * FROM (\n" . rtrim(trim($query), ';') . "\n) nsql_chunk";

        $last = null;
        while (true) {
            $sql = $base
                . ($last === null ? '' : " WHERE nsql_chunk.{$quoted} > {$placeholder}")
                . " ORDER BY nsql_chunk.{$quoted} LIMIT {$size}";

            $bound = $params;
            if ($last !== null) {
                if ($positional) {
                    $bound[] = $last;
                } else {
                    $bound['nsql_chunk_last'] = $last;
                }
            }

            $stmt = $this->execute_query($sql, $bound);
            if ($stmt === false) {
                return;
            }
            $rows = $stmt->fetchAll(PDO::FETCH_OBJ);
            $stmt->closeCursor();

            if ($rows === []) {
                return;
            }

            $tail = $rows[count($rows) - 1];
            if (! property_exists($tail, $column)) {
                throw new \InvalidArgumentException("chunk_by_id(): sorgu sonucunda '{$column}' kolonu yok.");
            }
            $last = $tail->{$column};

            yield $rows;

            if (count($rows) < $size) {
                return;
            }
        }
    }

    /**
     * Büyük veri setlerini chunk'lar halinde döndürür (LIMIT/OFFSET).
     *
     * Büyük tablolarda OFFSET maliyeti artar ve ORDER BY yoksa satır sırası garanti değildir;
     * birincil anahtarlı tablolarda chunk_by_id() tercih edin.
     *
     * @param string $query SQL sorgusu
     * @param array $params Sorgu parametreleri
     * @param int|null $chunk_size Chunk boyutu (opsiyonel, verilmezse Config'deki default değer kullanılır)
     * @return \Generator Her chunk için bir array döndürür
     */
    public function get_chunk(string $query, array $params = [], ?int $chunk_size = null): \Generator
    {
        $this->set_last_called_method();

        if ($this->has_top_level_limit($query)) {
            throw new \InvalidArgumentException('get_chunk() metodu LIMIT veya OFFSET içeren sorgularla kullanılamaz.');
        }

        $offset = 0;
        MemoryMonitor::set_chunk_size($chunk_size !== null && $chunk_size > 0
            ? $chunk_size
            : (int) Config::get('default_chunk_size', Config::default_chunk_size));
        $total_rows = 0;

        try {
            // Chunk size sabit belirtilmişse auto-adjust'u devre dışı bırak
            $use_auto_adjust = ($chunk_size === null);

            while (true) {
                // Memory kontrolü
                $this->check_memory_status();

                // Chunk size sabit belirtilmemişse auto-adjust kullan
                if ($use_auto_adjust) {
                    $this->adjust_chunk_size();
                }

                // Chunk sorgusu oluştur
                $current_chunk_size = MemoryMonitor::chunk_size();
                $chunk_query = $query . " LIMIT " . $current_chunk_size . " OFFSET " . $offset;

                $stmt = $this->execute_query($chunk_query, $params);
                if ($stmt === false) {
                    return;
                }
                $results = $stmt->fetchAll(PDO::FETCH_OBJ);
                $stmt->closeCursor();

                // Sonuç yoksa döngüyü bitir
                if (empty($results)) {
                    break;
                }

                // Sonuçları yield et
                yield $results;

                // Sayaçları güncelle
                $total_rows += count($results);
                $offset += $current_chunk_size;

                // Maksimum limit kontrolü
                if ($offset >= $this->max_result_set_size()) {
                    throw new \RuntimeException(
                        sprintf(
                            'Maksimum sonuç kümesi boyutu aşıldı! (Limit: %d)',
                            $this->max_result_set_size()
                        )
                    );
                }

                // Bellek optimizasyonu
                if ($offset % (Config::default_chunk_size * 10) === 0) {
                    $this->cleanup_resources();
                    gc_collect_cycles();
                }
            }
        } finally {
            gc_collect_cycles();
        }
    }

    /**
     * Bellek eşiklerini kontrol eder; uyarı/kritik seviyede cache'leri boşaltır.
     */
    private function check_memory_status(): void
    {
        MemoryMonitor::check(
            fn () => $this->cleanup_resources(),
            $this->debug_mode ? fn (string $message, string $data) => $this->log_debug_info($message, $data) : null
        );
    }

    /**
     * @return array{warning: int, critical: int}
     */
    private function memory_thresholds(): array
    {
        return MemoryMonitor::thresholds();
    }

    private function max_result_set_size(): int
    {
        return MemoryMonitor::max_result_set_size();
    }

    private function adjust_chunk_size(): void
    {
        MemoryMonitor::adjust_chunk_size(
            $this->debug_mode ? fn (string $message, string $data) => $this->log_debug_info($message, $data) : null
        );
    }

    private function cleanup_resources(): void
    {
        $this->clear_statement_cache();
        $this->clear_query_cache();
        gc_collect_cycles();
    }

    /**
     * Bellek istatistikleri (süreç geneli).
     */
    public function get_memory_stats(): array
    {
        return MemoryMonitor::stats();
    }
}
