<?php

namespace nsql\database\traits;

use nsql\database\security\query_analyzer;

trait query_analyzer_trait {
    private ?query_analyzer $query_analyzer = null;
    private bool $analyze_queries = false;

    /**
     * Query analyzer'ı aktive eder
     */
    public function enable_query_analysis(): void {
        $this->analyze_queries = true;
        if ($this->query_analyzer === null) {
            $this->query_analyzer = new query_analyzer();
        }
    }

    /**
     * Query analyzer'ı deaktive eder
     */
    public function disable_query_analysis(): void {
        $this->analyze_queries = false;
    }

    /**
     * Sorguyu analiz eder ve riskli ise exception fırlatır
     */
    protected function analyze_query(string $query): void {
        if (!$this->analyze_queries || $this->query_analyzer === null) {
            return;
        }

        $analysis = $this->query_analyzer->analyze_query($query);
        
        // Kritik risk varsa exception fırlat
        foreach ($analysis['issues'] as $issue) {
            if ($issue['risk_level'] === 'critical') {
                throw new \Exception(
                    "Kritik risk tespit edildi: {$issue['message']}\n" .
                    "Öneriler:\n" . implode("\n", $analysis['recommendations'])
                );
            }
        }

        // Debug modunda tüm analiz sonuçlarını logla
        if ($this->debug) {
            $this->log_debug_info('Query Analysis', $analysis);
        }
    }

    /**
     * Sorguyu execute etmeden önce analiz et
     */
    protected function before_execute_query(string $query): void {
        $this->analyze_query($query);
    }
}
