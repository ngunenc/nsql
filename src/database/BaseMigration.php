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

    /**
     * PostgreSQL / SQLite'ta up() ve down() transaction içinde çalışır; hata olursa yarım DDL geri alınır.
     * Transaction içinde çalışamayan işlemler (ör. PostgreSQL `CREATE INDEX CONCURRENTLY`) için false döndürün.
     * MySQL'de DDL örtük commit yaptığından transaction kullanılmaz.
     */
    public function within_transaction(): bool
    {
        return true;
    }
}
