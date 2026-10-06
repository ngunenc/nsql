<?php

namespace nsql\database\traits;

use nsql\database\exceptions\QueryException;
use nsql\database\security\QueryAnalyzer;

/**
 * Regex tabanlı sorgu denetimi (tanı aracı). Sorgu yürütmeye otomatik bağlı değildir ve SQL
 * injection koruması sağlamaz; koruma parametre bağlama ve quote_identifier() doğrulamasıdır.
 */
trait QueryAnalyzerTrait
{
    private ?QueryAnalyzer $query_analyzer = null;
    private bool $analyze_queries = false;
    private array $query_analysis_cache = []; // Analiz sonuçlarını cache'le
    private int $analysis_cache_hits = 0;
    private int $analysis_cache_misses = 0;

    /**
     * Query analyzer'ı aktive eder
     */
    public function enable_query_analysis(): void
    {
        $this->analyze_queries = true;
        if ($this->query_analyzer === null) {
            $this->query_analyzer = new QueryAnalyzer();
        }
    }

    /**
     * Query analyzer'ı deaktive eder
     */
    public function disable_query_analysis(): void
    {
        $this->analyze_queries = false;
    }

    /**
     * Sorguyu çalıştırmadan analiz eder (risk, performans ve şüpheli kalıp bulguları).
     *
     * @return array<string, mixed> query, issues, risk_score, recommendations
     */
    public function analyze_sql(string $query): array
    {
        $this->query_analyzer ??= new QueryAnalyzer();

        return $this->query_analyzer->analyze_query($query);
    }

    /**
     * Analiz açıksa sorguyu denetler; kritik bulguda QueryException fırlatır.
     */
    protected function analyze_query(string $query): void
    {
        if (! $this->analyze_queries || $this->query_analyzer === null) {
            return;
        }

        // Cache kontrolü (performans optimizasyonu)
        $query_hash = md5($query);
        if (isset($this->query_analysis_cache[$query_hash])) {
            $this->analysis_cache_hits++;
            $analysis = $this->query_analysis_cache[$query_hash];
        } else {
            $this->analysis_cache_misses++;
            $analysis = $this->query_analyzer->analyze_query($query);

            // Cache'e ekle (maksimum 100 analiz sonucu)
            if (count($this->query_analysis_cache) < 100) {
                $this->query_analysis_cache[$query_hash] = $analysis;
            }
        }

        // Kritik risk varsa exception fırlat
        foreach ($analysis['issues'] as $issue) {
            if ($issue['risk_level'] === 'critical') {
                throw new QueryException(
                    "Kritik risk tespit edildi: {$issue['message']}\n" .
                    "Öneriler:\n" . implode("\n", $analysis['recommendations']),
                    $query
                );
            }
        }

        // Debug modunda tüm analiz sonuçlarını logla
        if ($this->debug_mode) {
            $this->log_debug_info('Query Analysis', $analysis);
        }
    }

    /**
     * Sorguyu execute etmeden önce analiz et
     */
    protected function before_execute_query(string $query): void
    {
        $this->analyze_query($query);
    }

    /**
     * Query analyzer istatistiklerini döndürür
     */
    public function get_query_analyzer_stats(): array
    {
        $total_analyses = $this->analysis_cache_hits + $this->analysis_cache_misses;
        $cache_hit_rate = $total_analyses > 0 ? ($this->analysis_cache_hits / $total_analyses) * 100 : 0;

        return [
            'enabled' => $this->analyze_queries,
            'cache_size' => count($this->query_analysis_cache),
            'cache_hits' => $this->analysis_cache_hits,
            'cache_misses' => $this->analysis_cache_misses,
            'cache_hit_rate' => round($cache_hit_rate, 2),
            'total_analyses' => $total_analyses,
        ];
    }

    /**
     * Query analyzer cache'ini temizler
     */
    public function clear_query_analyzer_cache(): void
    {
        $this->query_analysis_cache = [];
        $this->analysis_cache_hits = 0;
        $this->analysis_cache_misses = 0;
    }
}
