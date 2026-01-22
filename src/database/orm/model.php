<?php

namespace nsql\database\orm;

use nsql\database\nsql;
use nsql\database\query_builder;

/**
 * Base Model Class
 * 
 * Active Record pattern implementasyonu
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
        $this->attributes = $attributes;
        
        // Tablo adını sınıf adından türet (eğer belirtilmemişse)
        if (empty($this->table)) {
            $this->table = $this->get_table_name_from_class();
        }
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
     * Yeni kayıt oluşturur
     */
    public function save(): bool
    {
        $data = $this->get_attributes();
        
        // Timestamps
        if ($this->timestamps) {
            $now = date('Y-m-d H:i:s');
            if (empty($this->attributes[$this->primary_key])) {
                // Yeni kayıt
                $data[$this->created_at_column] = $now;
            }
            $data[$this->updated_at_column] = $now;
        }

        if (isset($this->attributes[$this->primary_key])) {
            // Update
            $id = $this->attributes[$this->primary_key];
            $sql = "UPDATE {$this->table} SET ";
            $set_parts = [];
            $params = [];
            
            foreach ($data as $key => $value) {
                if ($key !== $this->primary_key) {
                    $set_parts[] = "{$key} = ?";
                    $params[] = $value;
                }
            }
            
            $sql .= implode(', ', $set_parts) . " WHERE {$this->primary_key} = ?";
            $params[] = $id;
            
            return $this->db->update($sql, $params);
        } else {
            // Insert
            $columns = array_keys($data);
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $columns_str = implode(', ', $columns);
            
            $sql = "INSERT INTO {$this->table} ({$columns_str}) VALUES ({$placeholders})";
            $result = $this->db->insert($sql, array_values($data));
            
            if ($result) {
                $this->attributes[$this->primary_key] = $this->db->insert_id();
            }
            
            return $result;
        }
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
        $sql = "DELETE FROM {$this->table} WHERE {$this->primary_key} = ?";
        
        return $this->db->delete($sql, [$id]);
    }

    /**
     * Attribute getter
     */
    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * Attribute setter
     */
    public function __set(string $key, mixed $value): void
    {
        if (empty($this->fillable) || in_array($key, $this->fillable)) {
            $this->attributes[$key] = $value;
        }
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
        return json_encode($this->to_array());
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
