<?php

namespace nsql\database\security;

class query_analyzer {
    private array $risk_patterns = [
        'delete_without_where' => '/DELETE\s+FROM\s+[`\w.]+\s*(?!WHERE)/i',
        'update_without_where' => '/UPDATE\s+[`\w.]+\s+SET\s+(?:[`\w.]+\s*=\s*[^,]+\s*,?\s*)+(?!WHERE)/i',
        'truncate_table' => '/TRUNCATE\s+TABLE\s+[`\w.]+/i',
        'drop_table' => '/DROP\s+TABLE\s+[`\w.]+/i',
        'alter_table' => '/ALTER\s+TABLE\s+[`\w.]+/i',
        'multiple_joins' => '/(?:JOIN\s+[`\w.]+\s+(?:ON|USING)(?:\s+[^,]+)?){4,}/i',
        'cartesian_join' => '/FROM\s+[`\w.]+(?:\s*,\s*[`\w.]+)+(?!\s+WHERE)/i',
        'select_all_columns' => '/SELECT\s+\*\s+FROM/i',
        'union_query' => '/UNION\s+(?:ALL\s+)?SELECT/i',
        'subquery_exists' => '/EXISTS\s*\(\s*SELECT/i',
        'large_in_clause' => '/IN\s*\([^)]{1000,}\)/i',
    ];

    private array $performance_patterns = [
        'missing_index_hint' => '/(?:JOIN|FROM)\s+[`\w.]+(?!\s+(?:USE|FORCE|IGNORE)\s+INDEX)/i',
        'order_by_rand' => '/ORDER\s+BY\s+RAND\(\)/i',
        'group_by_all' => '/GROUP\s+BY\s+\d+(?:\s*,\s*\d+){3,}/i',
        'distinct_all' => '/SELECT\s+DISTINCT/i',
        'having_without_group' => '/HAVING(?!\s+COUNT|\s+SUM|\s+AVG|\s+MAX|\s+MIN)/i',
        'like_wildcard_start' => '/LIKE\s+[\'"]%/i',
    ];

    private array $security_patterns = [
        'sql_comment' => '/--|\\/\\*|#/i',
        'system_table_access' => '/FROM\s+(?:mysql\.|information_schema\.|performance_schema\.)/i',
        'multiple_statements' => '/;\s*\w+/i',
        'potential_injection' => '/EXEC\(|EXECUTE\(|INTO\s+OUTFILE|INTO\s+DUMPFILE|LOAD\s+DATA|LOAD\s+XML/i',
        'privilege_escalation' => '/GRANT\s+|REVOKE\s+|CREATE\s+USER|DROP\s+USER/i',
    ];

    private array $risk_levels = [
        'critical' => 5,
        'high' => 4,
        'medium' => 3,
        'low' => 2,
        'info' => 1
    ];

    private array $pattern_risk_levels = [
        'delete_without_where' => 'critical',
        'update_without_where' => 'critical',
        'truncate_table' => 'critical',
        'drop_table' => 'critical',
        'alter_table' => 'high',
        'multiple_joins' => 'medium',
        'cartesian_join' => 'high',
        'select_all_columns' => 'low',
        'union_query' => 'medium',
        'subquery_exists' => 'low',
        'large_in_clause' => 'medium',
        'missing_index_hint' => 'info',
        'order_by_rand' => 'medium',
        'group_by_all' => 'medium',
        'distinct_all' => 'low',
        'having_without_group' => 'medium',
        'like_wildcard_start' => 'low',
        'sql_comment' => 'medium',
        'system_table_access' => 'high',
        'multiple_statements' => 'critical',
        'potential_injection' => 'critical',
        'privilege_escalation' => 'critical'
    ];

    /**
     * SQL sorgusunu analiz eder ve risk raporu oluşturur
     */
    public function analyze_query(string $query): array {
        $issues = [];
        
        // Risk pattern kontrolü
        foreach ($this->risk_patterns as $type => $pattern) {
            if (preg_match($pattern, $query)) {
                $issues[] = $this->create_issue(
                    $type,
                    'risk',
                    $this->get_risk_message($type)
                );
            }
        }

        // Performans pattern kontrolü
        foreach ($this->performance_patterns as $type => $pattern) {
            if (preg_match($pattern, $query)) {
                $issues[] = $this->create_issue(
                    $type,
                    'performance',
                    $this->get_performance_message($type)
                );
            }
        }

        // Güvenlik pattern kontrolü
        foreach ($this->security_patterns as $type => $pattern) {
            if (preg_match($pattern, $query)) {
                $issues[] = $this->create_issue(
                    $type,
                    'security',
                    $this->get_security_message($type)
                );
            }
        }

        // Ek analizler
        $this->analyze_query_complexity($query, $issues);
        $this->analyze_query_length($query, $issues);

        return [
            'query' => $query,
            'issues' => $issues,
            'risk_score' => $this->calculate_risk_score($issues),
            'recommendations' => $this->generate_recommendations($issues)
        ];
    }

    /**
     * Risk mesajını döndürür
     */
    private function get_risk_message(string $type): string {
        $messages = [
            'delete_without_where' => 'WHERE koşulu olmadan DELETE sorgusu tehlikelidir',
            'update_without_where' => 'WHERE koşulu olmadan UPDATE sorgusu tehlikelidir',
            'truncate_table' => 'TRUNCATE TABLE sorgusu tüm verileri siler',
            'drop_table' => 'DROP TABLE sorgusu tabloyu tamamen siler',
            'alter_table' => 'Tablo yapısını değiştiren sorgu tespit edildi',
            'multiple_joins' => 'Çok sayıda JOIN kullanımı performans sorunlarına yol açabilir',
            'cartesian_join' => 'Cartesian JOIN tespit edildi, performans sorunu oluşturabilir',
            'select_all_columns' => 'Tüm kolonların seçilmesi önerilmez, spesifik kolonları seçin',
            'union_query' => 'UNION sorguları performans sorunlarına yol açabilir',
            'subquery_exists' => 'EXISTS alt sorgusu tespit edildi',
            'large_in_clause' => 'Çok büyük IN bloğu tespit edildi'
        ];

        return $messages[$type] ?? 'Bilinmeyen risk türü';
    }

    /**
     * Performans mesajını döndürür
     */
    private function get_performance_message(string $type): string {
        $messages = [
            'missing_index_hint' => 'Index hint kullanımı performansı artırabilir',
            'order_by_rand' => 'RAND() kullanımı performans sorunlarına yol açar',
            'group_by_all' => 'Çok sayıda kolon ile GROUP BY kullanımı',
            'distinct_all' => 'DISTINCT kullanımı performansı etkileyebilir',
            'having_without_group' => 'GROUP BY olmadan HAVING kullanımı',
            'like_wildcard_start' => 'LIKE ile başlangıç wildcard kullanımı indeks kullanımını engeller'
        ];

        return $messages[$type] ?? 'Bilinmeyen performans sorunu';
    }

    /**
     * Güvenlik mesajını döndürür
     */
    private function get_security_message(string $type): string {
        $messages = [
            'sql_comment' => 'SQL yorum satırları güvenlik riski oluşturabilir',
            'system_table_access' => 'Sistem tablolarına erişim tespit edildi',
            'multiple_statements' => 'Çoklu SQL sorgusu tespit edildi',
            'potential_injection' => 'Potansiyel SQL injection riski',
            'privilege_escalation' => 'Yetki yükseltme riski tespit edildi'
        ];

        return $messages[$type] ?? 'Bilinmeyen güvenlik sorunu';
    }

    /**
     * Sorgu karmaşıklığını analiz eder
     */
    private function analyze_query_complexity(string $query, array &$issues): void {
        // Join sayısı
        $join_count = preg_match_all('/\bJOIN\b/i', $query);
        if ($join_count > 3) {
            $issues[] = $this->create_issue(
                'complex_joins',
                'performance',
                "Sorgu $join_count adet JOIN içeriyor, bu performansı etkileyebilir"
            );
        }

        // Where koşul sayısı
        $where_conditions = preg_match_all('/\bAND\b|\bOR\b/i', $query);
        if ($where_conditions > 5) {
            $issues[] = $this->create_issue(
                'complex_conditions',
                'performance',
                "Sorgu $where_conditions adet koşul içeriyor, bu performansı etkileyebilir"
            );
        }
    }

    /**
     * Sorgu uzunluğunu analiz eder
     */
    private function analyze_query_length(string $query, array &$issues): void {
        $length = strlen($query);
        if ($length > 1000) {
            $issues[] = $this->create_issue(
                'long_query',
                'performance',
                "Sorgu $length karakter uzunluğunda, bu karmaşıklığı artırabilir"
            );
        }
    }

    /**
     * Risk skorunu hesaplar
     */
    private function calculate_risk_score(array $issues): int {
        $score = 0;
        foreach ($issues as $issue) {
            $risk_level = $this->pattern_risk_levels[$issue['type']] ?? 'low';
            $score += $this->risk_levels[$risk_level];
        }
        return $score;
    }

    /**
     * Öneriler oluşturur
     */
    private function generate_recommendations(array $issues): array {
        $recommendations = [];
        foreach ($issues as $issue) {
            $recommendations[] = $this->get_recommendation($issue['type']);
        }
        return array_filter(array_unique($recommendations));
    }

    /**
     * Her sorun tipi için özel öneri döndürür
     */
    private function get_recommendation(string $type): string {
        $recommendations = [
            'delete_without_where' => 'WHERE koşulu ekleyin veya bilinçli olarak tüm verileri sildiğinizden emin olun',
            'update_without_where' => 'WHERE koşulu ekleyin veya bilinçli olarak tüm verileri güncellediğinizden emin olun',
            'truncate_table' => 'Veri kaybını önlemek için önce yedek alın',
            'drop_table' => 'Tablo silme işlemi geri alınamaz, dikkatli olun',
            'alter_table' => 'Şema değişikliklerini planlı bakım zamanlarında yapın',
            'multiple_joins' => 'Join sayısını azaltın veya sorguyu bölün',
            'cartesian_join' => 'JOIN koşullarını düzgün belirtin',
            'select_all_columns' => 'Sadece ihtiyaç duyulan kolonları seçin',
            'missing_index_hint' => 'EXPLAIN ile sorgu planını kontrol edin ve gerekli indeksleri ekleyin',
            'order_by_rand' => 'Rastgele sıralama için alternatif yöntemler kullanın',
            'like_wildcard_start' => 'LIKE ile başlangıç wildcard kullanımından kaçının'
        ];

        return $recommendations[$type] ?? '';
    }

    /**
     * Sorun kaydı oluşturur
     */
    private function create_issue(string $type, string $category, string $message): array {
        return [
            'type' => $type,
            'category' => $category,
            'message' => $message,
            'risk_level' => $this->pattern_risk_levels[$type] ?? 'low'
        ];
    }
}
