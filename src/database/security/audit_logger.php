<?php

namespace nsql\database\security;

class audit_logger {
    private string $logFile;
    private array $sensitiveFields = ['password', 'token', 'secret'];

    public function __construct(?string $logFile = null) {
        $this->logFile = $logFile ?? __DIR__ . '/../../logs/security.log';
    }

    /**
     * Güvenlik olayını logla
     */
    public function log(string $event, array $context = []): void {
        $timestamp = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user = $_SESSION['user_id'] ?? 'anonymous';
        
        // Hassas verileri filtrele
        $context = $this->filterSensitiveData($context);
        
        $logEntry = sprintf(
            "[%s] %s - IP: %s, User: %s, Event: %s, Context: %s\n",
            $timestamp,
            'SECURITY',
            $ip,
            $user,
            $event,
            json_encode($context, JSON_UNESCAPED_UNICODE)
        );

        $this->writeLog($logEntry);
    }

    /**
     * Log dosyasına yaz
     */
    private function writeLog(string $entry): void {
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (!file_put_contents($this->logFile, $entry, FILE_APPEND | LOCK_EX)) {
            throw new \RuntimeException('Log dosyasına yazılamadı: ' . $this->logFile);
        }
    }

    /**
     * Hassas verileri filtrele
     */
    private function filterSensitiveData(array $data): array {
        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), $this->sensitiveFields)) {
                $data[$key] = '********';
            } elseif (is_array($value)) {
                $data[$key] = $this->filterSensitiveData($value);
            }
        }
        return $data;
    }
}
