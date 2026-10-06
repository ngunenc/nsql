<?php

namespace nsql\database\drivers;

/**
 * PostgreSQL Driver
 */
class PgsqlDriver implements DriverInterface
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
        if (! str_starts_with($dsn, 'pgsql:')) {
            throw new \InvalidArgumentException('Geçersiz PostgreSQL DSN formatı');
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
            throw new \InvalidArgumentException('Geçersiz PostgreSQL DSN formatı');
        }

        $charset = 'UTF8';
        if (preg_match('/--client_encoding=([^\'"\s]+)/', $parts['options'] ?? '', $m)) {
            $charset = $m[1];
        }

        return [
            'driver' => 'pgsql',
            'host' => $host,
            'port' => ctype_digit($port) ? (int) $port : 5432,
            'dbname' => ($parts['dbname'] ?? '') !== '' ? $parts['dbname'] : null,
            'charset' => $charset,
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
            // lastval() oturumdaki son sequence değeridir (trigger başka sequence'i ilerletirse
            // yanlış olabilir); QueryBuilder/ORM bu yüzden INSERT ... RETURNING kullanır.
            // Oturumda sequence kullanılmadıysa hata verir → 0
            try {
                $stmt = $pdo->query('SELECT lastval()');
                $id = $stmt === false ? false : $stmt->fetchColumn();
            } catch (\PDOException) {
                return 0;
            }

            return InsertId::normalize($id);
        }

        try {
            return InsertId::normalize($pdo->lastInsertId($sequence));
        } catch (\PDOException) {
            return 0;
        }
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
