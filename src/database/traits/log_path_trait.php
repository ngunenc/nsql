<?php

namespace nsql\database\traits;

use nsql\database\config;

/**
 * Log Path Trait
 * 
 * Ortak log path ve directory metodları
 * GELISTIRME-010: Code duplication azaltma
 */
trait log_path_trait
{
    /**
     * Log dizinini oluşturur
     */
    protected function ensure_log_directory(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    /**
     * Log dosya yolunu çözümler
     */
    protected function resolve_log_path(string $path): string
    {
        // Mutlak yol ise olduğu gibi döndür
        if (preg_match('/^[A-Za-z]:\\\\|^\//', $path)) {
            return $path;
        }

        // Relative yol ise uygulama kökündeki log dizinine ekle (vendor/paket kökü değil)
        $default_log_dir = config::get_project_root()
            . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'logs';
        $log_dir = config::get('log_dir', $default_log_dir);
        if (! is_string($log_dir) || $log_dir === '') {
            $log_dir = $default_log_dir;
        }
        if (config::is_vendor_package_path($log_dir)) {
            $log_dir = config::resolve_away_from_vendor($log_dir)
                . DIRECTORY_SEPARATOR . 'storage'
                . DIRECTORY_SEPARATOR . 'logs';
        }

        return rtrim($log_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
    }
}
