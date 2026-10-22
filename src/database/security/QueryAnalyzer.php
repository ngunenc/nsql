<?php

namespace nsql\database\security;

class QueryAnalyzer
{
    /**
     * Literal'leri ve yorumları ayıklanmış ifade üzerinde aranır. `delete_without_where`,
     * `update_without_where` ve `cartesian_join` desenle değil, üst seviye metinle ayrıca denetlenir
     * (negatif lookahead'li desenler geri izlemeyle her sorguda eşleşiyordu). Yönetim komutları yalnızca
     * ifade başında eşleşir: `shutdown_at` kolonu SHUTDOWN değildir.
     */
    private array $risk_patterns = [
        'truncate_table' => '/(?:^|;)\s*TRUNCATE\b/i',
        'drop_table' => '/\bDROP\s+TABLE\b/i',
        'alter_table' => '/(?:^|;)\s*ALTER\s+TABLE\b/i',
        'multiple_joins' => '/(?:\bJOIN\s+[`"\w.]+\s+(?:\w+\s+)?(?:ON|USING)\b(?:\s+[^,]+)?){4,}/i',
        'select_all_columns' => '/\bSELECT\s+\*\s+FROM\b/i',
        'union_query' => '/\bUNION\s+(?:ALL\s+)?SELECT\b/i',
        'subquery_exists' => '/\bEXISTS\s*\(\s*SELECT\b/i',
        // Veritabanı / şema düzeyi işlemler
        'drop_database' => '/\bDROP\s+(?:DATABASE|SCHEMA)\b/i',
        'create_database' => '/(?:^|;)\s*CREATE\s+(?:DATABASE|SCHEMA)\b/i',
        'drop_index' => '/\bDROP\s+INDEX\b/i',
        'drop_view' => '/\bDROP\s+VIEW\b/i',
        'drop_procedure' => '/\bDROP\s+(?:PROCEDURE|FUNCTION)\b/i',
        'create_user' => '/\bCREATE\s+USER\b/i',
        'alter_user' => '/\bALTER\s+USER\b/i',
        'grant_privileges' => '/(?:^|;)\s*GRANT\b/i',
        'revoke_privileges' => '/(?:^|;)\s*REVOKE\b/i',
        'flush_privileges' => '/(?:^|;)\s*FLUSH\s+PRIVILEGES\b/i',
        'set_password' => '/(?:^|;)\s*SET\s+PASSWORD\b/i',
        'lock_tables' => '/(?:^|;)\s*LOCK\s+TABLES?\b/i',
        'unlock_tables' => '/(?:^|;)\s*UNLOCK\s+TABLES?\b/i',
        'kill_process' => '/(?:^|;)\s*KILL\b/i',
        'shutdown' => '/(?:^|;)\s*SHUTDOWN\b/i',
    ];

    /** Literal içeriğine bakan desenler orijinal sorguda aranır */
    private const ORIGINAL_TEXT_PATTERNS = ['like_wildcard_start', 'second_order_injection', 'encoded_payload', 'large_in_clause'];

    private array $performance_patterns = [
        'missing_index_hint' => '/\b(?:JOIN|FROM)\s+[`\w.]+(?!\s+(?:USE|FORCE|IGNORE)\s+INDEX)/i',
        'large_in_clause' => '/\bIN\s*\([^)]{1000,}\)/i',
        'order_by_rand' => '/ORDER\s+BY\s+RAND\(\)/i',
        'group_by_all' => '/GROUP\s+BY\s+\d+(?:\s*,\s*\d+){3,}/i',
        'distinct_all' => '/SELECT\s+DISTINCT/i',
        'having_without_group' => '/HAVING(?!\s+COUNT|\s+SUM|\s+AVG|\s+MAX|\s+MIN)/i',
        'like_wildcard_start' => '/LIKE\s+[\'"]%/i',
    ];

    private array $security_patterns = [
        'system_table_access' => '/\bFROM\s+(?:mysql\.|information_schema\.|performance_schema\.)/i',
        'multiple_statements' => '/;\s*\S/',
        'potential_injection' => '/\bEXEC(?:UTE)?\s*\(|\bINTO\s+(?:OUTFILE|DUMPFILE)\b|\bLOAD\s+(?:DATA|XML)\b/i',
        'privilege_escalation' => '/(?:^|;)\s*(?:GRANT|REVOKE)\b|\b(?:CREATE|DROP)\s+USER\b/i',
        // Kodlama ve fonksiyon tabanlı injection teknikleri
        'hex_encoding' => '/\b0x[0-9a-fA-F]+\b/i', // Hex encoding (SQL injection tekniği)
        'char_function' => '/\bCHAR\s*\([^)]+\)/i', // CHAR() function (SQL injection tekniği)
        'concat_function' => '/\bCONCAT\s*\([^)]+\)/i', // CONCAT() function (SQL injection tekniği)
        'benchmark_function' => '/\bBENCHMARK\s*\(/i', // BENCHMARK() (time-based SQL injection)
        'sleep_function' => '/\bSLEEP\s*\(/i', // SLEEP() (time-based SQL injection)
        'waitfor_delay' => '/WAITFOR\s+DELAY/i', // SQL Server time-based injection
        'pg_sleep' => '/\bPG_SLEEP\s*\(/i', // PostgreSQL time-based injection
        'information_schema_access' => '/\bFROM\s+information_schema\./i', // Information schema access
        'union_based_injection' => '/\bUNION\s+(?:ALL\s+)?SELECT\s+.*\b(?:NULL|0x|CHAR|CONCAT)\b/i', // Union-based injection pattern
        'boolean_based_injection' => '/\b(?:AND|OR)\s+(?:\d+\s*=\s*\d+|1\s*=\s*1|1\s*=\s*0)/i', // Boolean-based injection
        'time_based_injection' => '/\b(?:AND|OR)\s+(?:SLEEP|BENCHMARK|WAITFOR|PG_SLEEP)\s*\(/i', // Time-based injection
        'stacked_queries' => '/;\s*(?:SELECT|INSERT|UPDATE|DELETE|DROP|CREATE|ALTER)\b/i', // Stacked queries
        'second_order_injection' => '/\'\s*\+\s*[^+]*\+\s*\'/i', // Second-order injection pattern
        'encoded_payload' => '/%[0-9a-fA-F]{2}|\\x[0-9a-fA-F]{2}/i', // URL/Hex encoded payload
    ];

    private array $risk_levels = [
        'critical' => 5,
        'high' => 4,
        'medium' => 3,
        'low' => 2,
        'info' => 1,
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
        'privilege_escalation' => 'critical',
        // Veritabanı / şema düzeyi ve injection teknikleri
        'drop_database' => 'critical',
        'create_database' => 'high',
        'drop_index' => 'high',
        'drop_view' => 'high',
        'drop_procedure' => 'high',
        'create_user' => 'critical',
        'alter_user' => 'critical',
        'grant_privileges' => 'critical',
        'revoke_privileges' => 'critical',
        'flush_privileges' => 'critical',
        'set_password' => 'critical',
        'lock_tables' => 'high',
        'unlock_tables' => 'high',
        'kill_process' => 'high',
        'shutdown' => 'critical',
        'hex_encoding' => 'high',
        'char_function' => 'high',
        'concat_function' => 'high',
        'benchmark_function' => 'critical',
        'sleep_function' => 'critical',
        'waitfor_delay' => 'critical',
        'pg_sleep' => 'critical',
        'information_schema_access' => 'medium',
        // Yalnızca metin sezgisi: meşru `UNION SELECT NULL` ve QueryBuilder'ın boş IN için ürettiği
        // `AND 1 = 1` de eşleşir; engellemez (critical değil), raporlanır
        'union_based_injection' => 'high',
        'boolean_based_injection' => 'high',
        'time_based_injection' => 'critical',
        'stacked_queries' => 'critical',
        'second_order_injection' => 'high',
        'encoded_payload' => 'high',
    ];

    /**
     * SQL sorgusunu analiz eder ve risk raporu oluşturur
     */
    /**
     * @param string|null $driver `mysql`, `pgsql`, `sqlite`; `#` yalnızca MySQL'de (veya bilinmiyorsa) yorumdur
     */
    public function __construct(private ?string $driver = null)
    {
    }

    public function analyze_query(string $query): array
    {
        $issues = [];
        $hash_comments = $this->driver === null || $this->driver === 'mysql';
        // Literal / yorum içeriği ve sondaki `;` anahtar kelime sayılmaz
        $statement = rtrim(SqlText::strip($query, $hash_comments), " \t\n\r\0\x0B;");
        $top = rtrim(SqlText::top_level($query, $hash_comments), " \t\n\r\0\x0B;");
        $text = fn (string $type): string => in_array($type, self::ORIGINAL_TEXT_PATTERNS, true) ? $query : $statement;

        foreach ($this->unbounded_writes($top) as $type) {
            $issues[] = $this->create_issue($type, 'risk', $this->get_risk_message($type));
        }

        if (SqlText::strip_literals($query) !== SqlText::strip($query, $hash_comments)) {
            $issues[] = $this->create_issue('sql_comment', 'security', $this->get_security_message('sql_comment'));
        }

        // Risk pattern kontrolü
        foreach ($this->risk_patterns as $type => $pattern) {
            if (preg_match($pattern, $text($type))) {
                $issues[] = $this->create_issue(
                    $type,
                    'risk',
                    $this->get_risk_message($type)
                );
            }
        }

        // Performans pattern kontrolü
        foreach ($this->performance_patterns as $type => $pattern) {
            if (preg_match($pattern, $text($type))) {
                $issues[] = $this->create_issue(
                    $type,
                    'performance',
                    $this->get_performance_message($type)
                );
            }
        }

        // Güvenlik pattern kontrolü
        foreach ($this->security_patterns as $type => $pattern) {
            if (preg_match($pattern, $text($type))) {
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
            'recommendations' => $this->generate_recommendations($issues),
        ];
    }

    /**
     * Üst seviyede (alt sorgu dışında) WHERE'i olmayan DELETE / UPDATE ve virgüllü FROM.
     * Yalnızca ifade DELETE/UPDATE ile (isteğe bağlı CTE'den sonra) başlıyorsa: PostgreSQL
     * `ON CONFLICT … DO UPDATE SET` ve MySQL `ON DUPLICATE KEY UPDATE` UPDATE ifadesi değildir.
     *
     * @return list<string>
     */
    private function unbounded_writes(string $top): array
    {
        $types = [];
        $has_where = preg_match('/\bWHERE\b/i', $top) === 1;

        if (! $has_where && preg_match('/^\s*(?:WITH\s.*?)?\bDELETE\b/is', $top)) {
            $types[] = 'delete_without_where';
        }
        if (! $has_where && preg_match('/^\s*(?:WITH\s.*?)?\bUPDATE\b.*\bSET\b/is', $top)) {
            $types[] = 'update_without_where';
        }
        if (! $has_where && preg_match('/\bFROM\s+[`"\w.]+(?:\s+(?:AS\s+)?\w+)?\s*,\s*[`"\w.]+/i', $top)) {
            $types[] = 'cartesian_join';
        }

        return $types;
    }

    /**
     * Risk mesajını döndürür
     */
    private function get_risk_message(string $type): string
    {
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
            'large_in_clause' => 'Çok büyük IN bloğu tespit edildi',
            // Veritabanı / şema düzeyi işlemler
            'drop_database' => 'DROP DATABASE sorgusu tüm veritabanını siler',
            'create_database' => 'CREATE DATABASE sorgusu yeni veritabanı oluşturur',
            'drop_index' => 'DROP INDEX sorgusu index\'i siler',
            'drop_view' => 'DROP VIEW sorgusu view\'ı siler',
            'drop_procedure' => 'DROP PROCEDURE/FUNCTION sorgusu stored procedure\'ü siler',
            'create_user' => 'CREATE USER sorgusu yeni kullanıcı oluşturur',
            'alter_user' => 'ALTER USER sorgusu kullanıcı bilgilerini değiştirir',
            'grant_privileges' => 'GRANT sorgusu yetki verir',
            'revoke_privileges' => 'REVOKE sorgusu yetki kaldırır',
            'flush_privileges' => 'FLUSH PRIVILEGES sorgusu yetki tablolarını yeniler',
            'set_password' => 'SET PASSWORD sorgusu şifre değiştirir',
            'lock_tables' => 'LOCK TABLES sorgusu tabloları kilitler',
            'unlock_tables' => 'UNLOCK TABLES sorgusu tablo kilidini açar',
            'kill_process' => 'KILL sorgusu işlemi sonlandırır',
            'shutdown' => 'SHUTDOWN sorgusu veritabanı sunucusunu kapatır',
        ];

        return $messages[$type] ?? 'Bilinmeyen risk türü';
    }

    /**
     * Performans mesajını döndürür
     */
    private function get_performance_message(string $type): string
    {
        $messages = [
            'missing_index_hint' => 'Index hint kullanımı performansı artırabilir',
            'order_by_rand' => 'RAND() kullanımı performans sorunlarına yol açar',
            'group_by_all' => 'Çok sayıda kolon ile GROUP BY kullanımı',
            'distinct_all' => 'DISTINCT kullanımı performansı etkileyebilir',
            'having_without_group' => 'GROUP BY olmadan HAVING kullanımı',
            'like_wildcard_start' => 'LIKE ile başlangıç wildcard kullanımı indeks kullanımını engeller',
        ];

        return $messages[$type] ?? 'Bilinmeyen performans sorunu';
    }

    /**
     * Güvenlik mesajını döndürür
     */
    private function get_security_message(string $type): string
    {
        $messages = [
            'sql_comment' => 'SQL yorum satırları güvenlik riski oluşturabilir',
            'system_table_access' => 'Sistem tablolarına erişim tespit edildi',
            'multiple_statements' => 'Çoklu SQL sorgusu tespit edildi',
            'potential_injection' => 'Potansiyel SQL injection riski',
            'privilege_escalation' => 'Yetki yükseltme riski tespit edildi',
            // Kodlama ve fonksiyon tabanlı injection teknikleri
            'hex_encoding' => 'Hex encoding tespit edildi (SQL injection tekniği)',
            'char_function' => 'CHAR() fonksiyonu tespit edildi (SQL injection tekniği)',
            'concat_function' => 'CONCAT() fonksiyonu tespit edildi (SQL injection tekniği)',
            'benchmark_function' => 'BENCHMARK() fonksiyonu tespit edildi (time-based SQL injection)',
            'sleep_function' => 'SLEEP() fonksiyonu tespit edildi (time-based SQL injection)',
            'waitfor_delay' => 'WAITFOR DELAY tespit edildi (SQL Server time-based injection)',
            'pg_sleep' => 'PG_SLEEP() fonksiyonu tespit edildi (PostgreSQL time-based injection)',
            'information_schema_access' => 'Information schema erişimi tespit edildi',
            'union_based_injection' => 'Union-based SQL injection pattern tespit edildi',
            'boolean_based_injection' => 'Boolean-based SQL injection pattern tespit edildi',
            'time_based_injection' => 'Time-based SQL injection pattern tespit edildi',
            'stacked_queries' => 'Stacked queries tespit edildi (çoklu sorgu)',
            'second_order_injection' => 'Second-order SQL injection pattern tespit edildi',
            'encoded_payload' => 'Encoded payload tespit edildi (URL/Hex encoding)',
        ];

        return $messages[$type] ?? 'Bilinmeyen güvenlik sorunu';
    }

    /**
     * Sorgu karmaşıklığını analiz eder
     */
    private function analyze_query_complexity(string $query, array &$issues): void
    {
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
    private function analyze_query_length(string $query, array &$issues): void
    {
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
    private function calculate_risk_score(array $issues): int
    {
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
    private function generate_recommendations(array $issues): array
    {
        $recommendations = [];
        foreach ($issues as $issue) {
            $recommendations[] = $this->get_recommendation($issue['type']);
        }

        return array_filter(array_unique($recommendations));
    }

    /**
     * Her sorun tipi için özel öneri döndürür
     */
    private function get_recommendation(string $type): string
    {
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
            'like_wildcard_start' => 'LIKE ile başlangıç wildcard kullanımından kaçının',
        ];

        return $recommendations[$type] ?? '';
    }

    /**
     * Sorun kaydı oluşturur
     */
    private function create_issue(string $type, string $category, string $message): array
    {
        return [
            'type' => $type,
            'category' => $category,
            'message' => $message,
            'risk_level' => $this->pattern_risk_levels[$type] ?? 'low',
        ];
    }
}
