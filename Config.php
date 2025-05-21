<?php

class Config {
    private static array $config = [];
    private static bool $loaded = false;

    public static function load(): void {
        if (self::$loaded) {
            return;
        }

        $envFile = __DIR__ . '/.env';
        
        if (!file_exists($envFile)) {
            throw new RuntimeException('.env dosyası bulunamadı. Lütfen .env.example dosyasını .env olarak kopyalayın ve yapılandırın.');
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '#') === 0) {
                continue;
            }

            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Boolean değerleri dönüştür
            if (strtolower($value) === 'true') {
                $value = true;
            } elseif (strtolower($value) === 'false') {
                $value = false;
            }

            self::$config[$key] = $value;
        }

        self::$loaded = true;
    }

    public static function get(string $key, $default = null) {
        if (!self::$loaded) {
            self::load();
        }

        return self::$config[$key] ?? $default;
    }

    public static function all(): array {
        if (!self::$loaded) {
            self::load();
        }

        return self::$config;
    }
}
