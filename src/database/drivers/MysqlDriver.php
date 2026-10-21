<?php

namespace nsql\database\drivers;

/**
 * MySQL/MariaDB Driver
 */
class MysqlDriver implements DriverInterface
{
    use DsnParamsTrait;

    /** parse_dsn()'in ayrı alan olarak döndürdüğü anahtarlar; diğerleri `params` */
    private const KNOWN_KEYS = ['host', 'port', 'dbname', 'charset', 'user', 'password'];

    /**
     * @param array{host?: string, port?: int, dbname?: string, charset?: string, params?: array<string, mixed>} $config
     *        `params`: ek DSN parametreleri (`unix_socket` …)
     */
    public function build_dsn(array $config): string
    {
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 3306;
        $dbname = $config['dbname'] ?? '';
        $charset = $config['charset'] ?? 'utf8mb4';

        $dsn = 'mysql:host=' . self::dsn_value('host', $host);

        if (isset($config['port'])) {
            $dsn .= ';port=' . (int) $port;
        }

        if ($dbname) {
            $dsn .= ';dbname=' . self::dsn_value('dbname', $dbname);
        }

        $dsn .= ';charset=' . self::dsn_value('charset', $charset);

        return $dsn . self::dsn_params($config['params'] ?? []);
    }

    /**
     * @return array{driver: string, host: string, port: int, dbname: ?string, charset: string, user?: string, password?: string, params: array<string, string>}
     */
    public function parse_dsn(string $dsn): array
    {
        if (! str_starts_with($dsn, 'mysql:')) {
            throw new \InvalidArgumentException('Geçersiz MySQL DSN formatı');
        }

        $parts = self::parse_pairs(substr($dsn, 6));
        // Eski host:port yazımı
        [$host, $port] = self::split_host_port($parts['host'] ?? '', $parts['port'] ?? '');
        if ($host === '' && ($parts['unix_socket'] ?? '') === '') {
            throw new \InvalidArgumentException('Geçersiz MySQL DSN formatı');
        }

        $parsed = [
            'driver' => 'mysql',
            // unix_socket verildiğinde host yok sayılır
            'host' => $host !== '' ? $host : 'localhost',
            'port' => ctype_digit($port) ? (int) $port : 3306,
            'dbname' => ($parts['dbname'] ?? '') !== '' ? $parts['dbname'] : null,
            'charset' => ($parts['charset'] ?? '') !== '' ? $parts['charset'] : 'utf8mb4',
            'params' => array_diff_key($parts, array_flip(self::KNOWN_KEYS)),
        ];
        foreach (['user', 'password'] as $credential) {
            if (isset($parts[$credential])) {
                $parsed[$credential] = $parts[$credential];
            }
        }

        return $parsed;
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
        return InsertId::normalize($pdo->lastInsertId());
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
