<?php

namespace nsql\database\optimization;

/**
 * Query Optimizer
 *
 * Sorgu analizi ve index önerileri (suggest_indexes, analyze_performance) ile isteğe bağlı
 * MySQL index hint ekleme. SQL metni yeniden yazılmaz: regex tabanlı yeniden yazma string
 * literal'leri ve fonksiyon çağrılarını bozduğu için v2.1.1'de kaldırıldı.
 */
class QueryOptimizer
{
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * Sorguya istenirse MySQL index hint'leri ekler; başka bir değişiklik yapmaz.
     *
     * Seçenekler: `add_index_hints` (bool), `index_hints` (tablo => index adı veya adları).
     * `rewrite`, `optimize_subqueries`, `optimize_joins` seçenekleri yok sayılır (deprecated).
     *
     * @param string $query SQL sorgusu
     * @param array $options Optimizasyon seçenekleri
     * @return string Sorgu
     * @throws \InvalidArgumentException Tablo veya index adı geçersizse
     */
    public static function optimize(string $query, array $options = []): string
    {
        if ($options['add_index_hints'] ?? false) {
            return self::add_index_hints($query, $options['index_hints'] ?? []);
        }

        return $query;
    }

    /**
     * Index hint'leri ekler
     *
     * @param string $query SQL sorgusu
     * @param array<string, string|list<string>> $index_hints Tablo => index adı (virgüllü liste veya dizi)
     * @return string Index hint'li sorgu
     */
    private static function add_index_hints(string $query, array $index_hints): string
    {
        foreach ($index_hints as $table => $index) {
            $table = (string) $table;
            if (! preg_match(self::IDENTIFIER, $table)) {
                throw new \InvalidArgumentException('Geçersiz tablo adı (index hint): ' . substr($table, 0, 64));
            }

            $indexes = is_array($index) ? $index : explode(',', (string) $index);
            $indexes = array_map(static fn ($name) => trim((string) $name), $indexes);
            foreach ($indexes as $name) {
                if (! preg_match(self::IDENTIFIER, $name)) {
                    throw new \InvalidArgumentException('Geçersiz index adı: ' . substr($name, 0, 64));
                }
            }
            $hint = 'USE INDEX (' . implode(', ', $indexes) . ')';

            $query = (string) preg_replace(
                '/\b(FROM|JOIN)\s+([`"]?)' . $table . '\2(?![A-Za-z0-9_])/i',
                '$1 $2' . $table . '$2 ' . $hint,
                $query
            );
        }

        return $query;
    }

    /**
     * Sorgu için önerilen index'leri döndürür
     *
     * @param string $query SQL sorgusu
     * @return array Tablo => index önerileri
     */
    public static function suggest_indexes(string $query): array
    {
        $suggestions = [];

        // WHERE clause'dan index önerileri
        if (preg_match_all('/\bWHERE\s+([a-zA-Z0-9_\.]+)\s*[=<>]/i', $query, $matches)) {
            foreach ($matches[1] as $column) {
                if (strpos($column, '.') !== false) {
                    [$table, $col] = explode('.', $column, 2);
                    if (!isset($suggestions[$table])) {
                        $suggestions[$table] = [];
                    }
                    $suggestions[$table][] = $col;
                }
            }
        }

        // JOIN condition'lardan index önerileri
        if (preg_match_all('/\bJOIN\s+([a-zA-Z0-9_]+)\s+ON\s+([a-zA-Z0-9_\.]+)\s*=\s*([a-zA-Z0-9_\.]+)/i', $query, $matches)) {
            foreach ($matches[1] as $index => $table) {
                $left_col = $matches[2][$index];
                $right_col = $matches[3][$index];

                if (strpos($left_col, '.') !== false) {
                    [$tbl, $col] = explode('.', $left_col, 2);
                    if (!isset($suggestions[$tbl])) {
                        $suggestions[$tbl] = [];
                    }
                    $suggestions[$tbl][] = $col;
                }

                if (strpos($right_col, '.') !== false) {
                    [$tbl, $col] = explode('.', $right_col, 2);
                    if (!isset($suggestions[$tbl])) {
                        $suggestions[$tbl] = [];
                    }
                    $suggestions[$tbl][] = $col;
                }
            }
        }

        // ORDER BY'dan index önerileri
        if (preg_match_all('/\bORDER\s+BY\s+([a-zA-Z0-9_\.]+)/i', $query, $matches)) {
            foreach ($matches[1] as $column) {
                if (strpos($column, '.') !== false) {
                    [$table, $col] = explode('.', $column, 2);
                    if (!isset($suggestions[$table])) {
                        $suggestions[$table] = [];
                    }
                    $suggestions[$table][] = $col;
                }
            }
        }

        // Duplicate'leri kaldır
        foreach ($suggestions as $table => $columns) {
            $suggestions[$table] = array_unique($columns);
        }

        return $suggestions;
    }

    /**
     * Sorgu performans analizi yapar
     *
     * @param string $query SQL sorgusu
     * @return array Performans analiz sonuçları
     */
    public static function analyze_performance(string $query): array
    {
        $analysis = [
            'has_select_star' => preg_match('/\bSELECT\s+\*/i', $query),
            'has_where' => preg_match('/\bWHERE\s+/i', $query),
            'has_index_hint' => preg_match('/\b(USE|FORCE|IGNORE)\s+INDEX/i', $query),
            'join_count' => preg_match_all('/\bJOIN\s+/i', $query),
            'subquery_count' => preg_match_all('/\s*\(\s*SELECT\s+/i', $query),
            'order_by_count' => preg_match_all('/\bORDER\s+BY\s+/i', $query),
            'group_by_count' => preg_match_all('/\bGROUP\s+BY\s+/i', $query),
            'suggested_indexes' => self::suggest_indexes($query),
        ];

        // Performans skoru hesapla (0-100)
        $score = 100;

        if ($analysis['has_select_star']) {
            $score -= 10; // SELECT * kullanımı
        }

        if ($analysis['join_count'] > 3) {
            $score -= 5 * ($analysis['join_count'] - 3); // Çok fazla JOIN
        }

        if ($analysis['subquery_count'] > 2) {
            $score -= 5 * ($analysis['subquery_count'] - 2); // Çok fazla subquery
        }

        if (!$analysis['has_index_hint'] && !empty($analysis['suggested_indexes'])) {
            $score -= 15; // Index hint yok ama önerilen index'ler var
        }

        $analysis['performance_score'] = max(0, min(100, $score));

        return $analysis;
    }
}
