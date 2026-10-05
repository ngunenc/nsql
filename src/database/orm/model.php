<?php

namespace nsql\database\orm;

use nsql\database\config;
use nsql\database\nsql;
use nsql\database\query_builder;

/**
 * Base Model Class
 *
 * Active Record pattern implementasyonu
 *
 * Mass assignment (constructor, fill(), `$model->alan = ...`) yalnÄ±zca `$fillable` iÃ§indeki
 * alanlarÄ± kabul eder; `$fillable` boÅŸsa hiÃ§bir alan atanmaz. `$guarded` listesindeki alanlar
 * her zaman reddedilir; `$fillable` boÅŸ ve `$guarded` '*' deÄŸilse guarded dÄ±ÅŸÄ±ndaki her alan
 * atanabilir. GÃ¼venilir kaynaktan gelen veriler iÃ§in force_fill() / set_attribute() kullanÄ±n.
 *
 * Casting (`$casts`): int, float, bool, string, array/json (dizi), object (stdClass), datetime, date.
 * Okurken PHP tipine Ã§evrilir; yazarken veritabanÄ± deÄŸerine (json metni, 'Y-m-d H:i:s') saklanÄ±r.
 *
 * Ä°liÅŸkiler: alt sÄ±nÄ±fta parametresiz public metot olarak tanÄ±mlanÄ±r ve Ã¶zellik gibi eriÅŸildiÄŸinde
 * bir kez yÃ¼klenir (`$post->author`):
 *
 *     public function author(): ?User { return $this->belongs_to(User::class); }
 *     public function comments(): array { return $this->has_many(Comment::class); }
 *
 * Alt sÄ±nÄ±flar constructor'Ä± override ederse imzayÄ± (?nsql $db = null, array $attributes = []) korumalÄ±dÄ±r.
 *
 * @phpstan-consistent-constructor
 */
abstract class model implements \JsonSerializable
{
    protected nsql $db;
    protected string $table;
    protected string $primary_key = 'id';
    protected array $fillable = [];
    /** @var list<string> */
    protected array $guarded = ['*'];
    protected array $hidden = [];
    protected array $attributes = [];
    /** @var array<string, string> */
    protected array $casts = [];
    protected bool $timestamps = true;
    protected string $created_at_column = 'created_at';
    protected string $updated_at_column = 'updated_at';
    protected bool $soft_deletes = false;
    protected string $deleted_at_column = 'deleted_at';

    /** @var array<string, mixed> YÃ¼klenmiÅŸ iliÅŸkiler */
    private array $relations = [];

    /** @var array<string, array<string, bool>> */
    private static array $relation_methods = [];

    public function __construct(?nsql $db = null, array $attributes = [])
    {
        $this->db = $db ?? nsql::connection();

        // Tablo adÄ±nÄ± sÄ±nÄ±f adÄ±ndan tÃ¼ret (eÄŸer belirtilmemiÅŸse)
        if (empty($this->table)) {
            $this->table = $this->get_table_name_from_class();
        }

        $this->fill($attributes);
    }

    /**
     * SÄ±nÄ±f adÄ±ndan tablo adÄ±nÄ± tÃ¼retir.
     *
     * ORM_TABLE_NAMING=inflector: BlogPost â†’ blog_posts, Category â†’ categories, Person â†’ people.
     * VarsayÄ±lan (legacy, 1.x): strtolower(sÄ±nÄ±f) . 's' â€” v2.0'da inflector varsayÄ±lan olacak.
     */
    private function get_table_name_from_class(): string
    {
        if (strtolower((string) config::get('orm_table_naming', config::orm_table_naming)) === 'inflector') {
            return inflector::table_for_class(static::class);
        }

        $class_name = (new \ReflectionClass($this))->getShortName();

        return strtolower($class_name) . 's';
    }

    public function get_table(): string
    {
        return $this->table;
    }

    public function get_key_name(): string
    {
        return $this->primary_key;
    }

    public function get_key(): mixed
    {
        return $this->attributes[$this->primary_key] ?? null;
    }

    public function get_connection(): nsql
    {
        return $this->db;
    }

    /**
     * YalnÄ±zca fillable alanlarÄ± atar; diÄŸerlerini yok sayar.
     */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if ($this->is_fillable((string) $key)) {
                $this->attributes[$key] = $this->to_storage((string) $key, $value);
            }
        }

        return $this;
    }

    /**
     * Fillable kontrolÃ¼ olmadan atar. YalnÄ±zca gÃ¼venilir veriler iÃ§in kullanÄ±n.
     */
    public function force_fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $this->to_storage((string) $key, $value);
        }

        return $this;
    }

    /**
     * Tek bir alanÄ± fillable kontrolÃ¼ olmadan atar. YalnÄ±zca gÃ¼venilir veriler iÃ§in kullanÄ±n.
     */
    public function set_attribute(string $key, mixed $value): static
    {
        $this->attributes[$key] = $this->to_storage($key, $value);

        return $this;
    }

    /**
     * Cast uygulanmÄ±ÅŸ deÄŸer.
     */
    public function get_attribute(string $key): mixed
    {
        if (! array_key_exists($key, $this->attributes)) {
            return null;
        }

        return $this->from_storage($key, $this->attributes[$key]);
    }

    /**
     * VeritabanÄ±nda saklanan (cast uygulanmamÄ±ÅŸ) deÄŸer.
     */
    public function get_raw_attribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function is_fillable(string $key): bool
    {
        if (in_array($key, $this->guarded, true)) {
            return false;
        }
        if ($this->fillable !== []) {
            return in_array($key, $this->fillable, true);
        }

        return ! in_array('*', $this->guarded, true);
    }

    /**
     * Query builder instance dÃ¶ndÃ¼rÃ¼r (soft delete aÃ§Ä±ksa silinmiÅŸler hariÃ§)
     */
    public function query(): query_builder
    {
        $builder = $this->db->table($this->table);

        return $this->soft_deletes ? $builder->where_null($this->deleted_at_column) : $builder;
    }

    /**
     * Soft delete ile silinmiÅŸ kayÄ±tlar dahil query builder.
     */
    public function query_with_trashed(): query_builder
    {
        return $this->db->table($this->table);
    }

    /**
     * TÃ¼m kayÄ±tlarÄ± getirir (satÄ±r nesneleri; model Ã¶rnekleri iÃ§in get() kullanÄ±n)
     */
    public static function all(?nsql $db = null): array
    {
        $instance = new static($db);
        return $instance->query()->get();
    }

    /**
     * KayÄ±tlarÄ± model Ã¶rnekleri olarak getirir.
     *
     * @param (callable(query_builder): mixed)|null $scope Sorguyu daraltÄ±r: fn ($q) => $q->where(...)
     * @return list<static>
     */
    public static function get(?callable $scope = null, ?nsql $db = null): array
    {
        $instance = new static($db);
        $builder = $instance->query();
        if ($scope !== null) {
            $scope($builder);
        }

        return static::hydrate($builder->get(), $instance->db);
    }

    /**
     * @param (callable(query_builder): mixed)|null $scope
     */
    public static function first(?callable $scope = null, ?nsql $db = null): ?static
    {
        $instance = new static($db);
        $builder = $instance->query();
        if ($scope !== null) {
            $scope($builder);
        }
        $row = $builder->first();

        return $row === null ? null : $instance->new_from_row($row);
    }

    /**
     * ID'ye gÃ¶re kayÄ±t getirir
     */
    public static function find(int|string $id, ?nsql $db = null): ?static
    {
        $instance = new static($db);
        $result = $instance->query()
            ->where($instance->primary_key, '=', $id)
            ->first();

        if ($result) {
            $instance->attributes = (array) $result;
            return $instance;
        }

        return null;
    }

    /**
     * @throws ModelNotFoundException
     */
    public static function find_or_fail(int|string $id, ?nsql $db = null): static
    {
        return static::find($id, $db) ?? throw new ModelNotFoundException(static::class, $id);
    }

    /**
     * SatÄ±rlarÄ± (veritabanÄ± deÄŸerleriyle) model Ã¶rneklerine Ã§evirir.
     *
     * @param iterable<object|array<string, mixed>> $rows
     * @return list<static>
     */
    public static function hydrate(iterable $rows, ?nsql $db = null): array
    {
        $prototype = new static($db);
        $models = [];
        foreach ($rows as $row) {
            $models[] = $prototype->new_from_row($row);
        }

        return $models;
    }

    /**
     * @param object|array<string, mixed> $row
     */
    private function new_from_row(object|array $row): static
    {
        $model = new static($this->db);
        $model->attributes = (array) $row;

        return $model;
    }

    /**
     * KaydÄ± ekler veya gÃ¼nceller. Hidden alanlar da kaydedilir.
     *
     * @throws \InvalidArgumentException Tablo veya kolon adÄ± geÃ§ersizse
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

            $result = $this->db->update($sql, $params) !== false;
            if ($result && $this->timestamps) {
                $this->attributes[$this->updated_at_column] = $data[$this->updated_at_column];
            }

            return $result;
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
     * KayÄ±t siler. Soft delete aÃ§Ä±ksa yalnÄ±zca deleted_at doldurulur.
     */
    public function delete(): bool
    {
        if (! isset($this->attributes[$this->primary_key])) {
            return false;
        }

        if ($this->soft_deletes) {
            return $this->write_deleted_at(date('Y-m-d H:i:s'));
        }

        return $this->force_delete();
    }

    /**
     * Soft delete aÃ§Ä±k olsa bile kaydÄ± kalÄ±cÄ± olarak siler.
     */
    public function force_delete(): bool
    {
        if (! isset($this->attributes[$this->primary_key])) {
            return false;
        }

        $id = $this->attributes[$this->primary_key];
        $table = $this->db->quote_identifier($this->table);
        $primary_key = $this->db->quote_identifier($this->primary_key);

        return $this->db->delete("DELETE FROM {$table} WHERE {$primary_key} = ?", [$id]) !== false;
    }

    /**
     * Soft delete ile silinmiÅŸ kaydÄ± geri alÄ±r.
     */
    public function restore(): bool
    {
        if (! $this->soft_deletes || ! isset($this->attributes[$this->primary_key])) {
            return false;
        }

        return $this->write_deleted_at(null);
    }

    public function trashed(): bool
    {
        return $this->soft_deletes && ($this->attributes[$this->deleted_at_column] ?? null) !== null;
    }

    private function write_deleted_at(?string $value): bool
    {
        $table = $this->db->quote_identifier($this->table);
        $column = $this->db->quote_identifier($this->deleted_at_column);
        $primary_key = $this->db->quote_identifier($this->primary_key);

        $ok = $this->db->update(
            "UPDATE {$table} SET {$column} = ? WHERE {$primary_key} = ?",
            [$value, $this->attributes[$this->primary_key]]
        ) !== false;
        if ($ok) {
            $this->attributes[$this->deleted_at_column] = $value;
        }

        return $ok;
    }

    /**
     * Attribute getter (cast uygulanÄ±r); attribute yoksa tanÄ±mlÄ± iliÅŸki yÃ¼klenir.
     */
    public function __get(string $key): mixed
    {
        if (array_key_exists($key, $this->attributes)) {
            return $this->from_storage($key, $this->attributes[$key]);
        }
        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key];
        }
        if (self::is_relation_method(static::class, $key)) {
            return $this->relations[$key] = $this->{$key}();
        }

        return null;
    }

    /**
     * Attribute setter (yalnÄ±zca fillable alanlar)
     */
    public function __set(string $key, mixed $value): void
    {
        if ($this->is_fillable($key)) {
            $this->attributes[$key] = $this->to_storage($key, $value);
        }
    }

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]) || isset($this->relations[$key]);
    }

    /**
     * Ä°liÅŸkileri yÃ¼kler (veya yeniden yÃ¼kler).
     */
    public function load(string ...$relations): static
    {
        foreach ($relations as $relation) {
            if (! self::is_relation_method(static::class, $relation)) {
                throw new \InvalidArgumentException(static::class . "::{$relation}() bir iliÅŸki metodu deÄŸil.");
            }
            $this->relations[$relation] = $this->{$relation}();
        }

        return $this;
    }

    public function relation_loaded(string $relation): bool
    {
        return array_key_exists($relation, $this->relations);
    }

    public function unset_relation(string $relation): static
    {
        unset($this->relations[$relation]);

        return $this;
    }

    /**
     * Attribute'larÄ± dÃ¶ndÃ¼rÃ¼r (hidden olanlarÄ± hariÃ§, veritabanÄ± deÄŸerleriyle)
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
     * Cast uygulanmÄ±ÅŸ attribute'lar ve yÃ¼klenmiÅŸ iliÅŸkiler (hidden hariÃ§).
     * datetime/date deÄŸerleri metin olarak dÃ¶ner.
     */
    public function to_array(): array
    {
        $array = [];
        foreach ($this->get_attributes() as $key => $value) {
            $value = $this->from_storage((string) $key, $value);
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format($this->cast_type((string) $key) === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s');
            }
            $array[$key] = $value;
        }

        foreach ($this->relations as $name => $related) {
            if (in_array($name, $this->hidden, true)) {
                continue;
            }
            $array[$name] = match (true) {
                $related instanceof self => $related->to_array(),
                is_array($related) => array_map(fn ($item) => $item instanceof self ? $item->to_array() : $item, $related),
                default => $related,
            };
        }

        return $array;
    }

    /**
     * Attribute'larÄ± JSON olarak dÃ¶ndÃ¼rÃ¼r
     */
    public function to_json(): string
    {
        return (string) json_encode($this->to_array());
    }

    public function jsonSerialize(): array
    {
        return $this->to_array();
    }

    /**
     * Relationship: belongsTo. VarsayÄ±lan yabancÄ± anahtar: iliÅŸkili sÄ±nÄ±fÄ±n snake_case adÄ± + '_id'.
     *
     * @template T of model
     * @param class-string<T> $related_class
     * @return T|null
     */
    protected function belongs_to(string $related_class, ?string $foreign_key = null, ?string $owner_key = null): ?model
    {
        $foreign_key ??= inflector::snake(self::short_name($related_class)) . '_id';
        $foreign_value = $this->attributes[$foreign_key] ?? null;

        if ($foreign_value === null) {
            return null;
        }

        $related = new $related_class($this->db);
        $owner_key ??= $related->primary_key;
        $row = $related->query()->where($owner_key, '=', $foreign_value)->first();

        return $row === null ? null : $related->new_from_row($row);
    }

    /**
     * Relationship: hasOne. VarsayÄ±lan yabancÄ± anahtar: bu sÄ±nÄ±fÄ±n snake_case adÄ± + '_id'.
     *
     * @template T of model
     * @param class-string<T> $related_class
     * @return T|null
     */
    protected function has_one(string $related_class, ?string $foreign_key = null, ?string $local_key = null): ?model
    {
        $related = new $related_class($this->db);
        $builder = $this->related_query($related, $foreign_key, $local_key);
        if ($builder === null) {
            return null;
        }
        $row = $builder->first();

        return $row === null ? null : $related->new_from_row($row);
    }

    /**
     * Relationship: hasMany. VarsayÄ±lan yabancÄ± anahtar: bu sÄ±nÄ±fÄ±n snake_case adÄ± + '_id'.
     *
     * @template T of model
     * @param class-string<T> $related_class
     * @param (callable(query_builder): mixed)|null $scope SÄ±ralama/filtre: fn ($q) => $q->order_by('id')
     * @return list<T>
     */
    protected function has_many(string $related_class, ?string $foreign_key = null, ?string $local_key = null, ?callable $scope = null): array
    {
        $related = new $related_class($this->db);
        $builder = $this->related_query($related, $foreign_key, $local_key);
        if ($builder === null) {
            return [];
        }
        if ($scope !== null) {
            $scope($builder);
        }

        return $related::hydrate($builder->get(), $this->db);
    }

    private function related_query(model $related, ?string $foreign_key, ?string $local_key): ?query_builder
    {
        $foreign_key ??= inflector::snake(self::short_name(static::class)) . '_id';
        $local_value = $this->attributes[$local_key ?? $this->primary_key] ?? null;

        return $local_value === null ? null : $related->query()->where($foreign_key, '=', $local_value);
    }

    private static function short_name(string $class): string
    {
        return substr((string) strrchr('\\' . $class, '\\'), 1);
    }

    /**
     * Alt sÄ±nÄ±fta tanÄ±mlÄ±, public, statik olmayan ve parametresiz metotlar iliÅŸki sayÄ±lÄ±r.
     */
    private static function is_relation_method(string $class, string $method): bool
    {
        if (isset(self::$relation_methods[$class][$method])) {
            return self::$relation_methods[$class][$method];
        }

        $is_relation = false;
        if (method_exists($class, $method)) {
            $reflection = new \ReflectionMethod($class, $method);
            $is_relation = $reflection->isPublic() && ! $reflection->isStatic()
                && $reflection->getDeclaringClass()->getName() !== self::class
                && $reflection->getNumberOfRequiredParameters() === 0
                && ! str_starts_with($method, '__');
        }

        return self::$relation_methods[$class][$method] = $is_relation;
    }

    private function cast_type(string $key): ?string
    {
        $type = $this->casts[$key] ?? null;

        return $type === null ? null : strtolower($type);
    }

    private function from_storage(string $key, mixed $value): mixed
    {
        $type = $this->cast_type($key);
        if ($type === null || $value === null) {
            return $value;
        }

        return match ($type) {
            'int', 'integer' => (int) $value,
            'float', 'double', 'real', 'decimal' => (float) $value,
            'bool', 'boolean' => is_string($value) ? ! in_array(strtolower($value), ['', '0', 'f', 'false'], true) : (bool) $value,
            'string' => is_scalar($value) ? (string) $value : $value,
            'array', 'json' => is_string($value) ? json_decode($value, true) : (array) $value,
            'object' => is_string($value) ? json_decode($value) : (object) $value,
            'datetime' => self::to_datetime($value),
            'date' => self::to_datetime($value)?->setTime(0, 0),
            default => throw new \InvalidArgumentException(static::class . ": bilinmeyen cast tipi '{$type}' ({$key})"),
        };
    }

    private function to_storage(string $key, mixed $value): mixed
    {
        $type = $this->cast_type($key);
        if ($type === null || $value === null) {
            return $value;
        }

        return match ($type) {
            'array', 'json', 'object' => is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'datetime' => $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value,
            'date' => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value,
            'bool', 'boolean' => is_bool($value) ? $value : $this->from_storage($key, $value),
            'int', 'integer', 'float', 'double', 'real', 'decimal' => is_numeric($value) ? $this->from_storage($key, $value) : $value,
            default => $value,
        };
    }

    private static function to_datetime(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (new \DateTimeImmutable())->setTimestamp((int) $value);
        }

        return is_string($value) && $value !== '' ? new \DateTimeImmutable($value) : null;
    }
}
