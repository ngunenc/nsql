<?php

namespace nsql\database\drivers;

/**
 * MySQL/MariaDB Driver
 */
class mysql_driver implements driver_interface
{
    public function build_dsn(array $config): string
    {
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 3306;
        $dbname = $config['dbname'] ?? '';
        $charset = $config['charset'] ?? 'utf8mb4';

        $dsn = "mysql:host={$host}";

        if (isset($config['port'])) {
            $dsn .= ";port={$port}";
        }

        if ($dbname) {
            $dsn .= ";dbname={$dbname}";
        }

        $dsn .= ";charset={$charset}";

        return $dsn;
    }

    public function parse_dsn(string $dsn): array
    {
        if (! str_starts_with($dsn, 'mysql:')) {
            throw new \InvalidArgumentException('Geçersiz MySQL DSN formatı');
        }

        $parts = [];
        foreach (explode(';', substr($dsn, 6)) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if (trim($key) !== '') {
                $parts[strtolower(trim($key))] = trim($value);
            }
        }

        $host = $parts['host'] ?? '';
        $port = $parts['port'] ?? '';
        // Eski host:port yazımı
        if ($port === '' && preg_match('/^([^:]+):(\d+)$/', $host, $m)) {
            [$host, $port] = [$m[1], $m[2]];
        }
        if ($host === '') {
            throw new \InvalidArgumentException('Geçersiz MySQL DSN formatı');
        }

        return [
            'driver' => 'mysql',
            'host' => $host,
            'port' => ctype_digit($port) ? (int) $port : 3306,
            'dbname' => ($parts['dbname'] ?? '') !== '' ? $parts['dbname'] : null,
            'charset' => ($parts['charset'] ?? '') !== '' ? $parts['charset'] : 'utf8mb4',
        ];
    }

    public function get_pdo_options(): array
    {
        return [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => 0, // PHP 8.4 için int gerekiyor
            // MYSQL_ATTR_INIT_COMMAND PHP 8.4'te sorun yaratıyor, DSN'de charset zaten var
            // \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ];
    }

    public function get_driver_name(): string
    {
        return 'mysql';
    }

    public function get_last_insert_id(\PDO $pdo, ?string $sequence = null): int|string
    {
        return (int)$pdo->lastInsertId();
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
        return '`';
    }
}
