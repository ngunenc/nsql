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
            @mkdir($dir, 0775, true);
        }
    }

    /**
     * Log dosya yolunu çözümler
     */
    protected function resolve_log_path(string $path): string
    {
        return Config::resolve_log_path($path);
    }
}
