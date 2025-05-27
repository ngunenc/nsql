<?php

namespace nsql\database\traits;

use PDOStatement;
use nsql\database\Config;

trait statement_cache_trait {
    private array $statement_cache = [];
    private array $statement_cache_usage = [];

    /**
     * Statement cache anahtarı oluşturur
     */
    private function get_statement_cache_key(string $sql, array $params): string {
        return md5($sql . '|' . serialize(array_keys($params)) . '|' . serialize(array_map('gettype', $params)));
    }

    /**
     * Statement'ı önbelleğe ekler
     */
    private function add_to_statement_cache(string $key, PDOStatement $stmt): void {
        $this->statement_cache[$key] = $stmt;
        $this->statement_cache_usage[$key] = microtime(true);

        if (count($this->statement_cache) > $this->statement_cache_limit) {
            asort($this->statement_cache_usage);
            $oldest_key = array_key_first($this->statement_cache_usage);
            unset($this->statement_cache[$oldest_key], $this->statement_cache_usage[$oldest_key]);
        }
    }

    /**
     * Statement'ı önbellekten alır
     */
    private function get_from_statement_cache(string $key): ?PDOStatement {
        if (!isset($this->statement_cache[$key])) {
            return null;
        }

        $this->statement_cache_usage[$key] = microtime(true);
        return $this->statement_cache[$key];
    }

    /**
     * Statement önbelleğini temizler
     */
    public function clear_statement_cache(): void {
        $this->statement_cache = [];
        $this->statement_cache_usage = [];
    }
}
