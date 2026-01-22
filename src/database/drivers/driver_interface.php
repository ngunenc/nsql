<?php

namespace nsql\database\drivers;

/**
 * Database Driver Interface
 * 
 * Tüm database driver'ları bu interface'i implement etmelidir
 */
interface driver_interface
{
    /**
     * DSN oluşturur
     *
     * @param array $config Database yapılandırması
     * @return string DSN string
     */
    public function build_dsn(array $config): string;

    /**
     * DSN'den yapılandırmayı parse eder
     *
     * @param string $dsn DSN string
     * @return array Parsed configuration
     */
    public function parse_dsn(string $dsn): array;

    /**
     * Database'e özel PDO options döndürür
     *
     * @return array PDO options
     */
    public function get_pdo_options(): array;

    /**
     * Database driver adını döndürür
     *
     * @return string Driver name (mysql, pgsql, sqlite)
     */
    public function get_driver_name(): string;

    /**
     * Last insert ID'yi döndürür
     *
     * @param \PDO $pdo PDO connection
     * @param string|null $sequence Sequence name (PostgreSQL için)
     * @return int|string Last insert ID
     */
    public function get_last_insert_id(\PDO $pdo, ?string $sequence = null): int|string;

    /**
     * Database'e özel SQL limit/offset syntax'ını döndürür
     *
     * @param int $limit Limit
     * @param int $offset Offset
     * @return string SQL limit/offset clause
     */
    public function get_limit_clause(int $limit, int $offset = 0): string;

    /**
     * Database'e özel identifier quote karakterini döndürür
     *
     * @return string Quote character
     */
    public function get_identifier_quote(): string;
}
