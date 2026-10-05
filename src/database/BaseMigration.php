<?php

namespace nsql\database;

/**
 * Bağlantısı migration_manager tarafından enjekte edilen migration temeli.
 */
abstract class BaseMigration implements Migration
{
    protected ?Nsql $db = null;

    public function set_connection(Nsql $db): static
    {
        $this->db = $db;

        return $this;
    }

    protected function db(): Nsql
    {
        if ($this->db === null) {
            throw new \LogicException(static::class . ' için veritabanı bağlantısı ayarlanmadı (set_connection).');
        }

        return $this->db;
    }

    public function get_description(): string
    {
        return static::class;
    }

    public function get_dependencies(): array
    {
        return [];
    }
}
