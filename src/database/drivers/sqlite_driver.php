<?php

namespace nsql\database\drivers;

/**
 * SQLite Driver
 */
class sqlite_driver implements driver_interface
{
    public function build_dsn(array $config): string
    {
        $path = $config['path'] ?? $config['dbname'] ?? ':memory:';
        
        // Eğer path mutlak değilse, project root'a göre ayarla
        if ($path !== ':memory:' && ! str_starts_with($path, '/') && ! preg_match('/^[A-Z]:\\\\/', $path)) {
            $project_root = \nsql\database\config::get_project_root();
            if ($project_root) {
                $path = $project_root . '/' . $path;
            }
        }
        
        return "sqlite:{$path}";
    }

    public function parse_dsn(string $dsn): array
    {
        $pattern = '/sqlite:(.+)/';
        if (! preg_match($pattern, $dsn, $matches)) {
            throw new \InvalidArgumentException('Geçersiz SQLite DSN formatı');
        }

        $path = $matches[1];
        
        return [
            'driver' => 'sqlite',
            'path' => $path,
            'dbname' => $path === ':memory:' ? null : basename($path),
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
        return 'sqlite';
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
        return '"';
    }
}
