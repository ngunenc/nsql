<?php

namespace nsql\database\drivers;

/**
 * PostgreSQL Driver
 */
class pgsql_driver implements driver_interface
{
    public function build_dsn(array $config): string
    {
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 5432;
        $dbname = $config['dbname'] ?? '';
        $charset = $config['charset'] ?? 'UTF8';
        
        $dsn = "pgsql:host={$host}";
        
        if (isset($config['port'])) {
            $dsn .= ";port={$port}";
        }
        
        if ($dbname) {
            $dsn .= ";dbname={$dbname}";
        }
        
        if ($charset) {
            $dsn .= ";options='--client_encoding={$charset}'";
        }
        
        return $dsn;
    }

    public function parse_dsn(string $dsn): array
    {
        $pattern = '/pgsql:host=([^;:]+)(?::(\d+))?(?:;dbname=([^;]+))?(?:;options=\'--client_encoding=([^\']+)\')?/';
        if (! preg_match($pattern, $dsn, $matches)) {
            throw new \InvalidArgumentException('Geçersiz PostgreSQL DSN formatı');
        }

        return [
            'driver' => 'pgsql',
            'host' => $matches[1],
            'port' => isset($matches[2]) ? (int)$matches[2] : 5432,
            'dbname' => $matches[3] ?? null,
            'charset' => $matches[4] ?? 'UTF8',
        ];
    }

    public function get_pdo_options(): array
    {
        return [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => 0, // PHP 8.4 için int gerekiyor
        ];
    }

    public function get_driver_name(): string
    {
        return 'pgsql';
    }

    public function get_last_insert_id(\PDO $pdo, ?string $sequence = null): int|string
    {
        if ($sequence === null) {
            // PostgreSQL'de sequence belirtilmezse lastval() kullan
            $stmt = $pdo->query("SELECT lastval()");
            return $stmt->fetchColumn();
        }
        return $pdo->lastInsertId($sequence);
    }

    public function get_limit_clause(int $limit, int $offset = 0): string
    {
        if ($offset > 0) {
            return "LIMIT {$limit} OFFSET {$offset}";
        }
        return "LIMIT {$limit}";
    }

    public function get_identifier_quote(): string
    {
        return '"';
    }
}
