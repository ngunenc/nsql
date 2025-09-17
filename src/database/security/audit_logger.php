<?php

namespace nsql\database\security;

use nsql\database\Config;

class audit_logger
{
    private string $log_file;
    private array $sensitive_fields = [
        'password', 'token', 'secret', 'key', 'auth',
        'credit_card', 'card_number', 'cvv', 'ssn',
    ];

    public function __construct(?string $log_file = null)
    {
        $default = Config::get('audit_log_file', 'audit_log.txt');
        $this->log_file = $this->resolve_log_path($log_file ?? (string)$default);
        $this->ensure_log_directory(dirname($this->log_file));
    }

    /**
     * Güvenlik olayını loglar
     */
    public function log_security_event(
        string $event_type,
        string $description,
        array $context = [],
        string $severity = 'info'
    ): void {
        $log_entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'event_type' => $event_type,
            'severity' => $severity,
            'description' => $description,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'session_id' => session_id() ?: 'no_session',
            'context' => $this->sanitize_sensitive_data($context),
        ];

        $this->write_log($log_entry);
    }

    /**
     * Rate limit ihlallerini loglar
     */
    public function log_rate_limit_violation(
        string $identifier,
        string $request_type,
        array $context = []
    ): void {
        $this->log_security_event(
            'rate_limit_violation',
            "Rate limit aşıldı: $identifier ($request_type)",
            $context,
            'warning'
        );
    }

    /**
     * Session güvenlik olaylarını loglar
     */
    public function log_session_event(
        string $event_type,
        array $context = []
    ): void {
        $descriptions = [
            'session_start' => 'Yeni oturum başlatıldı',
            'session_destroy' => 'Oturum sonlandırıldı',
            'session_hijacking_attempt' => 'Oturum çalma girişimi tespit edildi',
            'session_expired' => 'Oturum süresi doldu',
            'session_regenerate' => 'Oturum ID yenilendi',
        ];

        $severity = ($event_type === 'session_hijacking_attempt') ? 'critical' : 'info';

        $this->log_security_event(
            $event_type,
            $descriptions[$event_type] ?? 'Oturum olayı',
            $context,
            $severity
        );
    }

    /**
     * SQL injection denemelerini loglar
     */
    public function log_sql_injection_attempt(
        string $query,
        array $params = [],
        string $error = ''
    ): void {
        $this->log_security_event(
            'sql_injection_attempt',
            'SQL injection girişimi tespit edildi',
            [
                'query' => $query,
                'parameters' => $this->sanitize_sensitive_data($params),
                'error' => $error,
            ],
            'critical'
        );
    }

    /**
     * Hassas veri erişim denemelerini loglar
     */
    public function log_sensitive_data_access(
        string $table,
        string $column,
        string $action,
        array $context = []
    ): void {
        $this->log_security_event(
            'sensitive_data_access',
            "Hassas veri erişimi: $table.$column ($action)",
            $context,
            'warning'
        );
    }

    /**
     * Bağlantı havuzu olaylarını loglar
     */
    public function log_pool_event(
        string $event_type,
        array $stats = []
    ): void {
        $this->log_security_event(
            'connection_pool_' . $event_type,
            "Bağlantı havuzu olayı: $event_type",
            $stats,
            'info'
        );
    }

    /**
     * Hassas verileri temizler
     */
    private function sanitize_sensitive_data(array $data): array
    {
        array_walk_recursive($data, function (&$value, $key) {
            if (in_array(strtolower($key), $this->sensitive_fields)) {
                $value = '******';
            }
        });

        return $data;
    }

    /**
     * Log dosyasına yazar (rota + rotasyon)
     */
    private function write_log(array $log_entry): void
    {
        $log_line = sprintf(
            "[%s] [%s] [%s] %s | IP: %s | UA: %s | SID: %s | %s\n",
            $log_entry['timestamp'],
            strtoupper($log_entry['severity']),
            $log_entry['event_type'],
            $log_entry['description'],
            $log_entry['ip_address'],
            substr($log_entry['user_agent'], 0, 150),
            $log_entry['session_id'],
            json_encode($log_entry['context'], JSON_UNESCAPED_UNICODE)
        );

        $this->rotate_if_needed($this->log_file);

        $result = file_put_contents(
            $this->log_file,
            $log_line,
            FILE_APPEND | LOCK_EX
        );

        if ($result === false) {
            error_log("Audit log yazma hatası: " . (error_get_last()['message'] ?? 'unknown'));
        }
    }

    private function ensure_log_directory(string $dir): void
    {
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    private function resolve_log_path(string $path): string
    {
        // Mutlak yol ise aynen kullan
        if (preg_match('/^[A-Za-z]:\\\\|^\//', $path)) {
            return $path;
        }
        $root = dirname(__DIR__, 3); // proje kökü
        $dir = Config::get('log_dir', $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs');

        return rtrim((string)$dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
    }

    private function rotate_if_needed(string $file): void
    {
        $max = (int)Config::get('log_max_size', 1048576); // 1MB varsayılan
        if (is_file($file) && filesize($file) > $max) {
            $rotated = $file . '.' . date('Ymd_His');
            @rename($file, $rotated);
        }
    }
}
