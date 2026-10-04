<?php

namespace nsql\database\orm;

use nsql\database\nsql;
use nsql\database\query_builder;

/**
 * Base Model Class
 *
 * Active Record pattern implementasyonu
 *
 * Mass assignment (constructor, fill(), `$model->alan = ...`) yalnızca `$fillable` içindeki
 * alanları kabul eder; `$fillable` boşsa hiçbir alan atanmaz. Güvenilir kaynaktan gelen
 * veriler için force_fill() / set_attribute() kullanın.
 */
abstract class model
{
    protected nsql $db;
    protected string $table;
    protected string $primary_key = 'id';
    protected array $fillable = [];
    protected array $hidden = [];
    protected array $attributes = [];
    protected bool $timestamps = true;
    protected string $created_at_column = 'created_at';
    protected string $updated_at_column = 'updated_at';

    public function __construct(?nsql $db = null, array $attributes = [])
    {
        $this->db = $db ?? new nsql();

        // Tablo adını sınıf adından türet (eğer belirtilmemişse)
        if (empty($this->table)) {
            $this->table = $this->get_table_name_from_class();
        }

        $this->fill($attributes);
    }

    /**
     * Sınıf adından tablo adını türetir
     */
    private function get_table_name_from_class(): string
    {
        $class_name = (new \ReflectionClass($this))->getShortName();
        // User -> users, Product -> products
        return strtolower($class_name) . 's';
    }

    /**
     * Yalnızca fillable alanları atar; diğerlerini yok sayar.
     */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if ($this->is_fillable((string) $key)) {
                $this->attributes[$key] = $value;
            }
        }

        return $this;
    }

    /**
     * Fillable kontrolü olmadan atar. Yalnızca güvenilir veriler için kullanın.
     */
    public function force_fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }

        return $this;
    }

    /**
     * Tek bir alanı fillable kontrolü olmadan atar. Yalnızca güvenilir veriler için kullanın.
     */
    public function set_attribute(string $key, mixed $value): static
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    public function is_fillable(string $key): bool
    {
        return in_array($key, $this->fillable, true);
    }

    /**
     * Query builder instance döndürür
     */
    public function query(): query_builder
    {
        return $this->db->table($this->table);
    }

    /**
     * Tüm kayıtları getirir
     */
    public static function all(?nsql $db = null): array
    {
        $instance = new static($db);
        return $instance->query()->get();
    }

    /**
     * ID'ye göre kayıt getirir
     */
    public static function find(int|string $id, ?nsql $db = null): ?static
    {
        $instance = new static($db);
        $result = $instance->query()
            ->where($instance->primary_key, '=', $id)
            ->first();

        if ($result) {
            $instance->attributes = (array)$result;
            return $instance;
        }

        return null;
    }

    /**
     * Kaydı ekler veya günceller. Hidden alanlar da kaydedilir.
     *
     * @throws \InvalidArgumentException Tablo veya kolon adı geçersizse
     */
    public function save(): bool
    {
        $data = $this->attributes;
        $is_new = empty($this->attributes[$this->primary_key]);

        if ($this->timestamps) {
            $now = date('Y-m-d H:i:s');
            if ($is_new) {
                $data[$this->created_at_column] = $now;
            }
            $data[$this->updated_at_column] = $now;
        }

        $table = $this->db->quote_identifier($this->table);
        $primary_key = $this->db->quote_identifier($this->primary_key);

        if (! $is_new) {
            $id = $this->attributes[$this->primary_key];
            unset($data[$this->primary_key]);

            if ($data === []) {
                return true;
            }

            $set_parts = [];
            foreach (array_keys($data) as $column) {
                $set_parts[] = $this->db->quote_identifier((string) $column) . ' = ?';
            }

            $sql = "UPDATE {$table} SET " . implode(', ', $set_parts) . " WHERE {$primary_key} = ?";
            $params = array_values($data);
            $params[] = $id;

            return $this->db->update($sql, $params);
        }

        unset($data[$this->primary_key]);

        if ($data === []) {
            throw new \InvalidArgumentException('Kaydedilecek alan yok.');
        }

        $columns = array_map(fn ($column) => $this->db->quote_identifier((string) $column), array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ") VALUES ({$placeholders})";
        $result = $this->db->insert($sql, array_values($data));

        if ($result === false) {
            return false;
        }

        $this->attributes[$this->primary_key] = $this->db->insert_id();
        if ($this->timestamps) {
            $this->attributes[$this->created_at_column] = $data[$this->created_at_column];
            $this->attributes[$this->updated_at_column] = $data[$this->updated_at_column];
        }

        return true;
    }

    /**
     * Kayıt siler
     */
    public function delete(): bool
    {
        if (! isset($this->attributes[$this->primary_key])) {
            return false;
        }

        $id = $this->attributes[$this->primary_key];
        $table = $this->db->quote_identifier($this->table);
        $primary_key = $this->db->quote_identifier($this->primary_key);

        return $this->db->delete("DELETE FROM {$table} WHERE {$primary_key} = ?", [$id]);
    }

    /**
     * Attribute getter
     */
    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * Attribute setter (yalnızca fillable alanlar)
     */
    public function __set(string $key, mixed $value): void
    {
        if ($this->is_fillable($key)) {
            $this->attributes[$key] = $value;
        }
    }

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    /**
     * Attribute'ları döndürür (hidden olanları hariç)
     */
    public function get_attributes(): array
    {
        $attributes = $this->attributes;

        foreach ($this->hidden as $hidden_key) {
            unset($attributes[$hidden_key]);
        }

        return $attributes;
    }

    /**
     * Attribute'ları array olarak döndürür
     */
    public function to_array(): array
    {
        return $this->get_attributes();
    }

    /**
     * Attribute'ları JSON olarak döndürür
     */
    public function to_json(): string
    {
        return (string) json_encode($this->to_array());
    }

    /**
     * Relationship: belongsTo
     */
    protected function belongs_to(string $related_class, string $foreign_key, string $owner_key = 'id'): ?model
    {
        $related = new $related_class($this->db);
        $foreign_value = $this->attributes[$foreign_key] ?? null;

        if ($foreign_value === null) {
            return null;
        }

        return $related::find($foreign_value, $this->db);
    }

    /**
     * Relationship: hasMany
     */
    protected function has_many(string $related_class, string $foreign_key, string $local_key = 'id'): array
    {
        $related = new $related_class($this->db);
        $local_value = $this->attributes[$local_key] ?? null;

        if ($local_value === null) {
            return [];
        }

        return $related->query()
            ->where($foreign_key, '=', $local_value)
            ->get();
    }
}
