<?php

namespace nsql\database\drivers;

/**
 * Database Driver Factory
 *
 * Driver instance'ları oluşturur
 */
class DriverFactory
{
    /**
     * DSN'den driver oluşturur
     *
     * @param string $dsn DSN string
     * @return DriverInterface Driver instance
     */
    public static function create_from_dsn(string $dsn): DriverInterface
    {
        // DSN'den driver tipini çıkar
        if (str_starts_with($dsn, 'mysql:')) {
            return new MysqlDriver();
        } elseif (str_starts_with($dsn, 'pgsql:') || str_starts_with($dsn, 'postgresql:')) {
            return new PgsqlDriver();
        } elseif (str_starts_with($dsn, 'sqlite:')) {
            return new SqliteDriver();
        }

        throw new \InvalidArgumentException("Desteklenmeyen database driver: {$dsn}");
    }

    /**
     * Driver adından driver oluşturur
     *
     * @param string $driver_name Driver adı (mysql, pgsql, sqlite)
     * @return DriverInterface Driver instance
     */
    public static function create(string $driver_name): DriverInterface
    {
        return match (strtolower($driver_name)) {
            'mysql', 'mariadb' => new MysqlDriver(),
            'pgsql', 'postgresql', 'postgres' => new PgsqlDriver(),
            'sqlite', 'sqlite3' => new SqliteDriver(),
            default => throw new \InvalidArgumentException("Desteklenmeyen database driver: {$driver_name}"),
        };
    }
}
