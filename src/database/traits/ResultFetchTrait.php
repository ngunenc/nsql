<?php

namespace nsql\database\traits;

use InvalidArgumentException;
use nsql\database\Config;
use nsql\database\security\SensitiveDataFilter;
use PDO;
use PDOStatement;

/**
 * Ham SQL okuma yardımcıları: get_var(), get_col() ve get_row()/get_results() çıktı biçimi
 * (Nsql::OBJECT / ARRAY_A / ARRAY_N) çözümü, debug için adlı sonuç ve büyük sonuç uyarısı.
 */
trait ResultFetchTrait
{
    /**
     * Çıktı biçimine göre fetch modunu döndürür.
     *
     * @throws InvalidArgumentException Desteklenmeyen biçim
     */
    private function resolve_fetch_mode(string|int $output): int
    {
        // Geriye uyum: önceki sürümlerde üçüncü parametre yoktu ve PDO::FETCH_* sabiti sessizce yok sayılırdı
        if (is_int($output)) {
            return PDO::FETCH_OBJ;
        }

        return match ($output) {
            self::OBJECT => PDO::FETCH_OBJ,
            self::ARRAY_A => PDO::FETCH_ASSOC,
            self::ARRAY_N => PDO::FETCH_NUM,
            // Değer kullanıcı girdisinden gelebilir: mesaja yalnızca güvenli biçimde yazılır (log enjeksiyonu)
            default => throw new InvalidArgumentException(sprintf(
                'Geçersiz çıktı biçimi: %s (Nsql::OBJECT, Nsql::ARRAY_A veya Nsql::ARRAY_N olmalı)',
                preg_match('/^[A-Za-z0-9_]{1,32}$/', $output) === 1 ? $output : 'string(' . strlen($output) . ')'
            )),
        };
    }

    /**
     * Debug çıktısı için sütun adları. SensitiveDataFilter hassas alanları ada göre maskelediğinden
     * adsız (sayısal / tek değer) sonuçlar debug'a adlı anahtarlarla yazılmalıdır. Yalnızca debug modunda çağrılır.
     *
     * @return array<int, string>|null Tekrar eden ada "#<indeks>" eklenir; meta alınamazsa null
     */
    private function debug_column_names(PDOStatement $stmt): ?array
    {
        $names = [];
        $count = $stmt->columnCount();
        for ($i = 0; $i < $count; $i++) {
            try {
                $meta = $stmt->getColumnMeta($i);
            } catch (\Throwable) {
                return null;
            }
            if (! is_array($meta) || ! isset($meta['name']) || $meta['name'] === '') {
                return null;
            }
            $name = (string) $meta['name'];
            $names[] = in_array($name, $names, true) ? $name . '#' . $i : $name;
        }

        return $names;
    }

    /**
     * Sayısal satırları debug için adlı anahtarlı hale getirir. Ad yoksa fail-closed: değerler maskelenir.
     *
     * @param array<int, mixed> $rows Sayısal dizinli satırlar
     * @param array<int, string>|null $names
     * @return array<int, array<int|string, mixed>>
     */
    private function name_debug_rows(array $rows, ?array $names): array
    {
        return array_map(static function (mixed $row) use ($names): array {
            $row = array_values((array) $row);
            if ($names === null || count($names) !== count($row)) {
                return array_fill(0, count($row), SensitiveDataFilter::MASK);
            }

            return array_combine($names, $row);
        }, $rows);
    }

    /**
     * Önbellek anahtarı öneki. OBJECT öneksiz bırakılır: preload_query() aynı anahtar düzenini kullanır
     * ve mevcut önbellek girdileri geçersiz olmaz.
     */
    private function output_key_prefix(string|int $output): string
    {
        return is_int($output) || $output === self::OBJECT ? '' : $output . ':';
    }

    /**
     * Büyük sonuç kümesi uyarısı (get_results / get_col)
     */
    private function warn_large_result_set(int $result_count): void
    {
        if ($result_count > (int) $this->setting('large_result_warning', Config::large_result_warning)) {
            trigger_error(
                "Büyük veri seti ($result_count satır). chunk_by_id() veya get_yield() kullanmayı düşünün.",
                E_USER_NOTICE
            );
        }
    }

    /**
     * Sorgunun ilk satırındaki ilk değeri döndürür. Satır yoksa veya değer NULL ise null döner.
     */
    public function get_var(string $query, array $params = []): mixed
    {
        $this->set_last_called_method();
        $query = $this->with_single_row_limit($query);

        // Değer null/0/'' olabildiğinden önbellekte { v: değer } sarmalı saklanır (miss ile ayrılır)
        $cache_key = $this->query_cache_usable() ? $this->generate_query_cache_key('var:' . $query, $params) : null;
        if ($cache_key !== null) {
            $cached = $this->get_from_query_cache($cache_key);
            if (is_array($cached) && array_key_exists('v', $cached)) {
                return $cached['v'];
            }
        }

        $stmt = $this->execute_query($query, $params);
        if ($stmt === false) {
            return null;
        }

        // fetchColumn() kullanılmaz: "satır yok" ile boolean false değeri (pdo_pgsql) ayırt edilemiyordu
        $row = $stmt->fetch(PDO::FETCH_NUM);
        $has_row = is_array($row);
        $value = $has_row ? ($row[0] ?? null) : null;
        $debug_names = $this->debug_mode && $has_row ? $this->debug_column_names($stmt) : null;
        $stmt->closeCursor();

        $this->last_results = $this->debug_mode && $has_row
            ? $this->name_debug_rows([[$value]], $debug_names === null ? null : [$debug_names[0]])
            : [];
        if ($has_row && $cache_key !== null) {
            $this->add_to_query_cache($cache_key, ['v' => $value], [], $this->extract_tables_from_query($query));
        }

        return $value;
    }

    /**
     * Sorgu sonucunda tek sütunun değerlerini döndürür.
     *
     * @param int $x Sütun indeksi (0 tabanlı)
     * @return array<int, mixed>
     * @throws InvalidArgumentException Negatif veya sorgunun sütun sayısını aşan indeks
     */
    public function get_col(string $query, array $params = [], int $x = 0): array
    {
        if ($x < 0) {
            throw new InvalidArgumentException("get_col() sütun indeksi negatif olamaz: $x");
        }
        $this->set_last_called_method();

        $this->check_memory_status();

        $cache_key = $this->query_cache_usable() ? $this->generate_query_cache_key("col:$x:" . $query, $params) : null;
        if ($cache_key !== null) {
            $cached = $this->get_from_query_cache($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $stmt = $this->execute_query($query, $params);
        if ($stmt === false) {
            return [];
        }

        // fetchAll(FETCH_COLUMN) sınır dışı indekste ham ValueError fırlatır ve imleci açık bırakırdı
        $column_count = $stmt->columnCount();
        if ($x >= $column_count) {
            $stmt->closeCursor();

            throw new InvalidArgumentException("get_col(): sütun indeksi $x geçersiz, sorgu $column_count sütun döndürüyor");
        }

        $debug_names = $this->debug_mode ? $this->debug_column_names($stmt) : null;
        $results = $stmt->fetchAll(PDO::FETCH_COLUMN, $x);
        $stmt->closeCursor();

        $result_count = count($results);
        $this->warn_large_result_set($result_count);

        $this->last_results = $this->debug_mode
            ? $this->name_debug_rows(
                array_map(static fn (mixed $value): array => [$value], $results),
                $debug_names === null ? null : [$debug_names[$x]]
            )
            : [];
        if ($cache_key !== null && $result_count <= $this->query_cache_max_rows()) {
            $this->add_to_query_cache($cache_key, $results, [], $this->extract_tables_from_query($query));
        }

        return $results;
    }
}
