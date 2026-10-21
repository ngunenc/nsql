<?php

namespace nsql\database\drivers;

/**
 * PostgreSQL Driver
 */
class PgsqlDriver implements DriverInterface
{
    use DsnParamsTrait;

    /** parse_dsn()'in ayrı alan olarak döndürdüğü anahtarlar; diğerleri `params` */
    private const KNOWN_KEYS = ['host', 'port', 'dbname', 'user', 'password'];

    /**
     * @param array{host?: string, port?: int, dbname?: string, charset?: string, params?: array<string, mixed>} $config
     *        `params`: ek DSN parametreleri (`sslmode`, `sslrootcert`, `application_name` …).
     *        `params.options` verilirse client_encoding yoksa ona eklenir.
     */
    public function build_dsn(array $config): string
    {
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 5432;
        $dbname = $config['dbname'] ?? '';
        $charset = $config['charset'] ?? 'UTF8';
        $params = $config['params'] ?? [];

        $dsn = 'pgsql:host=' . self::dsn_value('host', $host);

        if (isset($config['port'])) {
            $dsn .= ';port=' . (int) $port;
        }

        if ($dbname) {
            $dsn .= ';dbname=' . self::dsn_value('dbname', $dbname);
        }

        $options = trim((string) ($params['options'] ?? ''), " '\"");
        unset($params['options']);
        if ($charset && ! str_contains($options, 'client_encoding')) {
            $options = trim('--client_encoding=' . self::dsn_value('charset', $charset) . ' ' . $options);
        }
        if ($options !== '') {
            $dsn .= ";options='" . self::dsn_value('options', $options) . "'";
        }

        return $dsn . self::dsn_params($params);
    }

    /**
     * @return array{driver: string, host: string, port: int, dbname: ?string, charset: string, user?: string, password?: string, params: array<string, string>}
     */
    public function parse_dsn(string $dsn): array
    {
        if (! str_starts_with($dsn, 'pgsql:')) {
            throw new \InvalidArgumentException('Geçersiz PostgreSQL DSN formatı');
        }

        $parts = self::parse_pairs(substr($dsn, 6));
        // Eski host:port yazımı
        [$host, $port] = self::split_host_port($parts['host'] ?? '', $parts['port'] ?? '');
        if ($host === '') {
            throw new \InvalidArgumentException('Geçersiz PostgreSQL DSN formatı');
        }

        $charset = 'UTF8';
        $params = array_diff_key($parts, array_flip(self::KNOWN_KEYS));
        if (preg_match('/--client_encoding=([^\'"\s]+)/', $params['options'] ?? '', $m)) {
            $charset = $m[1];
            // Yalnızca client_encoding içeren options charset'e dönüşür; başka seçenek varsa korunur
            $rest = trim((string) preg_replace('/--client_encoding=[^\'"\s]+/', '', trim($params['options'], " '\"")));
            if ($rest === '') {
                unset($params['options']);
            }
        }

        $parsed = [
            'driver' => 'pgsql',
            'host' => $host,
            'port' => ctype_digit($port) ? (int) $port : 5432,
            'dbname' => ($parts['dbname'] ?? '') !== '' ? $parts['dbname'] : null,
            'charset' => $charset,
            'params' => $params,
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
