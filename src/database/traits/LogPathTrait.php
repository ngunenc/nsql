<?php

namespace nsql\database\traits;

use nsql\database\Config;

/**
 * Log Path Trait
 *
 * Logger ve AuditLogger için ortak log yolu ve dizin metodları
 */
trait LogPathTrait
{
    /**
     * Log dizinini oluşturur
     */
    protected function ensure_log_directory(string $dir): void
    {
        if (!is_dir($dir)) {
            // Loglar SQL ve hata ayrıntısı içerir: diğer kullanıcılar dizini listeleyemez
            @mkdir($dir, 0750, true);
        }
    }

    /**
     * Satırı log dosyasına ekler. Yeni oluşturulan dosya 0640 iznini alır; umask 022 ile 0644
     * (herkes okuyabilir) oluyordu.
     */
    protected function append_log_line(string $file, string $line): int|false
    {
        $created = ! is_file($file);
        $result = @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        if ($created && $result !== false) {
            @chmod($file, 0640);
        }

        return $result;
    }

    /**
     * Log dosya yolunu çözümler
     */
    protected function resolve_log_path(string $path): string
    {
        return Config::resolve_log_path($path);
    }
}
