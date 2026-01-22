<?php

namespace nsql\database\logging;

use nsql\database\config;
use nsql\database\traits\log_path_trait;

/**
 * Logger - Structured Logging, Log Levels, Log Rotation
 * 
 * Özellikler:
 * - JSON format structured logging
 * - Log levels (DEBUG, INFO, WARNING, ERROR, CRITICAL)
 * - Size-based ve time-based rotation
 * - Log compression
 * - Environment-based log levels
 */
class logger
{
    use log_path_trait;
    
    // Log seviyeleri (RFC 5424 uyumlu)
    public const DEBUG = 100;
    public const INFO = 200;
    public const NOTICE = 250;
    public const WARNING = 300;
    public const ERROR = 400;
    public const CRITICAL = 500;
    public const ALERT = 550;
    public const EMERGENCY = 600;

    private string $log_file;
    private int $log_level;
    private bool $structured_format;
    private ?int $max_file_size;
    private ?int $max_files;
    private ?int $rotation_interval; // saniye cinsinden
    private bool $compress_old_logs;
    private ?int $last_rotation_time = null;

    private static array $level_names = [
        self::DEBUG => 'DEBUG',
        self::INFO => 'INFO',
        self::NOTICE => 'NOTICE',
        self::WARNING => 'WARNING',
        self::ERROR => 'ERROR',
        self::CRITICAL => 'CRITICAL',
        self::ALERT => 'ALERT',
        self::EMERGENCY => 'EMERGENCY',
    ];

    public function __construct(
        ?string $log_file = null,
        ?int $log_level = null,
        bool $structured_format = true
    ) {
        $this->log_file = $this->resolve_log_path($log_file ?? config::get('log_file', 'nsql.log'));
        $this->structured_format = $structured_format;
        
        // Log level belirleme (environment-based)
        $this->log_level = $log_level ?? $this->get_environment_log_level();
        
        // Rotation ayarları
        $this->max_file_size = (int)config::get('log_max_size', 10 * 1024 * 1024); // 10MB default
        $this->max_files = (int)config::get('log_max_files', 10);
        $this->rotation_interval = (int)config::get('log_rotation_interval', 86400); // 24 saat default
        $this->compress_old_logs = (bool)config::get('log_compress', true);
        
        $this->ensure_log_directory(dirname($this->log_file));
    }

    /**
     * Environment'a göre log level belirler
     */
    private function get_environment_log_level(): int
    {
        $env = config::get_environment();
        
        return match ($env) {
            'production' => self::WARNING, // Production'da sadece WARNING ve üzeri
            'testing' => self::DEBUG, // Testing'de tüm loglar
            'development' => self::INFO, // Development'da INFO ve üzeri
            default => self::WARNING,
        };
    }

    /**
     * Log yazar (structured format)
     */
    public function log(int $level, string $message, array $context = []): void
    {
        // Log level kontrolü
        if ($level < $this->log_level) {
            return;
        }

        // Rotation kontrolü
        $this->rotate_if_needed();

        // Structured log entry oluştur
        $log_entry = [
            'timestamp' => date('c'), // ISO 8601 format
            'level' => self::$level_names[$level] ?? 'UNKNOWN',
            'level_code' => $level,
            'message' => $message,
            'context' => $context,
            'environment' => config::get_environment(),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
        ];

        // IP, user agent gibi ek bilgiler (eğer mevcutsa)
        $client_ip = \nsql\database\security\security_manager::get_client_ip();
        if ($client_ip !== 'unknown') {
            $log_entry['ip_address'] = $client_ip;
        }
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $log_entry['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
        }

        // Log formatına göre yaz
        if ($this->structured_format) {
            $this->write_structured_log($log_entry);
        } else {
            $this->write_text_log($log_entry);
        }
    }

    /**
     * Structured (JSON) format log yazar
     */
    private function write_structured_log(array $log_entry): void
    {
        $log_line = json_encode($log_entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        
        $result = @file_put_contents(
            $this->log_file,
            $log_line,
            FILE_APPEND | LOCK_EX
        );

        if ($result === false) {
            error_log("Log yazma hatası: " . (error_get_last()['message'] ?? 'unknown'));
        }
    }

    /**
     * Text format log yazar (backward compatibility)
     */
    private function write_text_log(array $log_entry): void
    {
        $timestamp = $log_entry['timestamp'];
        $level = $log_entry['level'];
        $message = $log_entry['message'];
        $context = !empty($log_entry['context']) ? ' | ' . json_encode($log_entry['context'], JSON_UNESCAPED_UNICODE) : '';
        
        $log_line = "[$timestamp] [$level] $message$context" . PHP_EOL;
        
        $result = @file_put_contents(
            $this->log_file,
            $log_line,
            FILE_APPEND | LOCK_EX
        );

        if ($result === false) {
            error_log("Log yazma hatası: " . (error_get_last()['message'] ?? 'unknown'));
        }
    }

    /**
     * Convenience metodları
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log(self::DEBUG, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log(self::NOTICE, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log(self::CRITICAL, $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log(self::ALERT, $message, $context);
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->log(self::EMERGENCY, $message, $context);
    }

    /**
     * Log rotation kontrolü ve uygulaması
     */
    private function rotate_if_needed(): void
    {
        $now = time();
        $should_rotate = false;

        // Size-based rotation
        if ($this->max_file_size && is_file($this->log_file)) {
            if (filesize($this->log_file) >= $this->max_file_size) {
                $should_rotate = true;
            }
        }

        // Time-based rotation
        if ($this->rotation_interval) {
            if ($this->last_rotation_time === null) {
                $this->last_rotation_time = $now;
            } elseif (($now - $this->last_rotation_time) >= $this->rotation_interval) {
                $should_rotate = true;
            }
        }

        if ($should_rotate) {
            $this->rotate_log();
            $this->last_rotation_time = $now;
        }
    }

    /**
     * Log dosyasını rotate eder
     */
    private function rotate_log(): void
    {
        if (!is_file($this->log_file)) {
            return;
        }

        $timestamp = date('Ymd_His');
        $rotated_file = $this->log_file . '.' . $timestamp;

        // Mevcut log dosyasını rotate et
        if (@rename($this->log_file, $rotated_file)) {
            // Eski log dosyalarını temizle
            $this->cleanup_old_logs();

            // Compression
            if ($this->compress_old_logs && function_exists('gzencode')) {
                $this->compress_log($rotated_file);
            }
        }
    }

    /**
     * Eski log dosyalarını temizler
     */
    private function cleanup_old_logs(): void
    {
        if (!$this->max_files) {
            return;
        }

        $log_dir = dirname($this->log_file);
        $log_basename = basename($this->log_file);
        $pattern = $log_dir . DIRECTORY_SEPARATOR . $log_basename . '.*';

        $files = glob($pattern);
        if (!$files) {
            return;
        }

        // Dosya adına göre sırala (en yeni önce)
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        // Max files'ı aşan dosyaları sil
        if (count($files) > $this->max_files) {
            $files_to_delete = array_slice($files, $this->max_files);
            foreach ($files_to_delete as $file) {
                @unlink($file);
                // Compressed versiyonu varsa onu da sil
                if (file_exists($file . '.gz')) {
                    @unlink($file . '.gz');
                }
            }
        }
    }

    /**
     * Log dosyasını compress eder
     */
    private function compress_log(string $file): void
    {
        if (!function_exists('gzencode') || !is_file($file)) {
            return;
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            return;
        }

        $compressed = @gzencode($content, 9); // Level 9 (maximum compression)
        if ($compressed !== false) {
            $compressed_file = $file . '.gz';
            if (@file_put_contents($compressed_file, $compressed) !== false) {
                // Orijinal dosyayı sil (sadece compressed versiyonu tut)
                @unlink($file);
            }
        }
    }

    // Log path metodları artık log_path_trait'te (GELISTIRME-010)

    /**
     * Log level'ı değiştirir
     */
    public function set_log_level(int $level): void
    {
        $this->log_level = $level;
    }

    /**
     * Mevcut log level'ı döndürür
     */
    public function get_log_level(): int
    {
        return $this->log_level;
    }

    /**
     * Log level adını döndürür
     */
    public static function get_level_name(int $level): string
    {
        return self::$level_names[$level] ?? 'UNKNOWN';
    }
}
