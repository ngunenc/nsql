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
        return config::resolve_log_path($path);
    }
}
