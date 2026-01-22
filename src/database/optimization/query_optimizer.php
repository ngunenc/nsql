<?php

namespace nsql\database\optimization;

/**
 * Query Optimizer
 * 
 * SQL sorgularını optimize eder:
 * - Index hint ekleme
 * - Query rewriting
 * - Subquery optimization
 * - Join optimization
 */
class query_optimizer
{
    /**
     * Sorguyu optimize eder
     * 
     * @param string $query SQL sorgusu
     * @param array $options Optimizasyon seçenekleri
     * @return string Optimize edilmiş sorgu
     */
    public static function optimize(string $query, array $options = []): string
    {
        $optimized = $query;
        
        // Query rewriting
        if ($options['rewrite'] ?? true) {
            $optimized = self::rewrite_query($optimized);
        }
        
        // Index hint ekleme
        if ($options['add_index_hints'] ?? false) {
            $optimized = self::add_index_hints($optimized, $options['index_hints'] ?? []);
        }
        
        // Subquery optimization
        if ($options['optimize_subqueries'] ?? true) {
            $optimized = self::optimize_subqueries($optimized);
        }
        
        // Join optimization
        if ($options['optimize_joins'] ?? true) {
            $optimized = self::optimize_joins($optimized);
        }
        
        return $optimized;
    }
    
    /**
     * Query rewriting yapar
     * 
     * @param string $query SQL sorgusu
     * @return string Rewrite edilmiş sorgu
     */
    private static function rewrite_query(string $query): string
    {
        $rewritten = $query;
        
        // SELECT * → belirli sütunlar (basit durumlar için)
        // Not: Bu genel bir kural değil, sadece örnek
        
        // WHERE 1=1 kaldırma
        $rewritten = preg_replace('/\bWHERE\s+1\s*=\s*1\s*(AND|$)/i', 'WHERE $1', $rewritten);
        $rewritten = preg_replace('/\bWHERE\s+1\s*=\s*1\s*$/i', '', $rewritten);
        
        // Gereksiz parantezleri temizle
        $rewritten = preg_replace('/\(\s*([a-zA-Z0-9_\.]+)\s*\)/i', '$1', $rewritten);
        
        // Çoklu boşlukları temizle
        $rewritten = preg_replace('/\s+/', ' ', $rewritten);
        $rewritten = trim($rewritten);
        
        return $rewritten;
    }
    
    /**
     * Index hint'leri ekler
     * 
     * @param string $query SQL sorgusu
     * @param array $index_hints Tablo => index mapping
     * @return string Index hint'li sorgu
     */
    private static function add_index_hints(string $query, array $index_hints): string
    {
        if (empty($index_hints)) {
            return $query;
        }
        
        $optimized = $query;
        
        foreach ($index_hints as $table => $index) {
            // USE INDEX hint ekle
            $pattern = '/\bFROM\s+([`"]?)' . preg_quote($table, '/') . '\1/i';
            $replacement = "FROM $1$table$1 USE INDEX ($index)";
            
            if (preg_match($pattern, $optimized)) {
                $optimized = preg_replace($pattern, $replacement, $optimized, 1);
            }
            
            // JOIN'lerde de index hint ekle
            $pattern = '/\bJOIN\s+([`"]?)' . preg_quote($table, '/') . '\1/i';
            $replacement = "JOIN $1$table$1 USE INDEX ($index)";
            
            if (preg_match($pattern, $optimized)) {
                $optimized = preg_replace($pattern, $replacement, $optimized);
            }
        }
        
        return $optimized;
    }
    
    /**
     * Subquery'leri optimize eder
     * 
     * @param string $query SQL sorgusu
     * @return string Optimize edilmiş sorgu
     */
    private static function optimize_subqueries(string $query): string
    {
        $optimized = $query;
        
        // EXISTS subquery'leri optimize et
        // SELECT * FROM table1 WHERE EXISTS (SELECT 1 FROM table2 WHERE ...)
        // → JOIN kullanılabilir (basit durumlar için)
        
        // IN subquery'leri için index kullanımını öner
        // Bu genellikle veritabanı tarafında yapılır, burada sadece pattern tespiti
        
        return $optimized;
    }
    
    /**
     * JOIN'leri optimize eder
     * 
     * @param string $query SQL sorgusu
     * @return string Optimize edilmiş sorgu
     */
    private static function optimize_joins(string $query): string
    {
        $optimized = $query;
        
        // JOIN sıralamasını optimize et (küçük tabloları önce)
        // Bu karmaşık bir optimizasyon, burada sadece temel pattern tespiti
        
        // INNER JOIN'leri optimize et
        // WHERE koşullarını JOIN ON'a taşı (bazı durumlarda daha hızlı)
        
        return $optimized;
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
