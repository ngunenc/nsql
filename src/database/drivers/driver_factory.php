<?php

namespace nsql\database\drivers;

/**
 * Database Driver Factory
 * 
 * Driver instance'ları oluşturur
 */
class driver_factory
{
    /**
     * DSN'den driver oluşturur
     *
     * @param string $dsn DSN string
     * @return driver_interface Driver instance
     */
    public static function create_from_dsn(string $dsn): driver_interface
    {
        // DSN'den driver tipini çıkar
        if (str_starts_with($dsn, 'mysql:')) {
            return new mysql_driver();
        } elseif (str_starts_with($dsn, 'pgsql:') || str_starts_with($dsn, 'postgresql:')) {
            return new pgsql_driver();
        } elseif (str_starts_with($dsn, 'sqlite:')) {
            return new sqlite_driver();
        }
        
        throw new \InvalidArgumentException("Desteklenmeyen database driver: {$dsn}");
    }

    /**
     * Driver adından driver oluşturur
     *
     * @param string $driver_name Driver adı (mysql, pgsql, sqlite)
     * @return driver_interface Driver instance
     */
    public static function create(string $driver_name): driver_interface
    {
        return match (strtolower($driver_name)) {
            'mysql', 'mariadb' => new mysql_driver(),
            'pgsql', 'postgresql', 'postgres' => new pgsql_driver(),
            'sqlite', 'sqlite3' => new sqlite_driver(),
            default => throw new \InvalidArgumentException("Desteklenmeyen database driver: {$driver_name}"),
        };
    }
}
