<?php

namespace nsql\database;

/**
 * Bağlantısı migration_manager tarafından enjekte edilen migration temeli.
 */
abstract class base_migration implements migration
{
    protected ?nsql $db = null;

    public function set_connection(nsql $db): static
    {
        $this->db = $db;

        return $this;
    }

    protected function db(): nsql
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
