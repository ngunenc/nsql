<?php

namespace nsql\database\orm;

use nsql\database\Config;
use nsql\database\Nsql;
use nsql\database\QueryBuilder;

/**
 * Base Model Class
 *
 * Active Record pattern implementasyonu
 *
 * Mass assignment (constructor, fill(), `$model->alan = ...`) yalnızca `$fillable` içindeki
 * alanları kabul eder; `$fillable` boşsa hiçbir alan atanmaz. `$guarded` listesindeki alanlar
 * her zaman reddedilir; `$fillable` boş ve `$guarded` '*' değilse guarded dışındaki her alan
 * atanabilir. Güvenilir kaynaktan gelen veriler için force_fill() / set_attribute() kullanın.
 *
 * Casting (`$casts`): int, float, bool, string, array/json (dizi), object (stdClass), datetime, date.
 * Okurken PHP tipine çevrilir; yazarken veritabanı değerine (json metni, 'Y-m-d H:i:s') saklanır.
 *
 * İlişkiler: alt sınıfta parametresiz public metot olarak tanımlanır ve özellik gibi erişildiğinde
 * bir kez yüklenir (`$post->author`):
 *
 *     public function author(): ?User { return $this->belongs_to(User::class); }
 *     public function comments(): array { return $this->has_many(Comment::class); }
 *
 * Alt sınıflar constructor'ı override ederse imzayı (?nsql $db = null, array $attributes = []) korumalıdır.
 *
 * @phpstan-consistent-constructor
 */
abstract class Model implements \JsonSerializable
{
    protected Nsql $db;
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

    /** @var array<string, mixed> Yüklenmiş ilişkiler */
    private array $relations = [];

    /** @var array<string, array<string, bool>> */
    private static array $relation_methods = [];

    public function __construct(?Nsql $db = null, array $attributes = [])
    {
        $this->db = $db ?? Nsql::connection();

        // Tablo adını sınıf adından türet (eğer belirtilmemişse)
        if (empty($this->table)) {
            $this->table = $this->get_table_name_from_class();
        }

        $this->fill($attributes);
    }

    /**
     * Sınıf adından tablo adını türetir.
     *
     * Varsayılan (inflector): BlogPost → blog_posts, Category → categories, Person → people.
     * ORM_TABLE_NAMING=legacy (1.x davranışı): strtolower(sınıf) . 's'.
     */
    private function get_table_name_from_class(): string
    {
        if (strtolower((string) Config::get('orm_table_naming', Config::orm_table_naming)) === 'inflector') {
            return Inflector::table_for_class(static::class);
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

    public function get_connection(): Nsql
    {
        return $this->db;
    }

    /**
     * Yalnızca fillable alanları atar; diğerlerini yok sayar.
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
     * Fillable kontrolü olmadan atar. Yalnızca güvenilir veriler için kullanın.
     */
    public function force_fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $this->to_storage((string) $key, $value);
        }

        return $this;
    }

    /**
     * Tek bir alanı fillable kontrolü olmadan atar. Yalnızca güvenilir veriler için kullanın.
     */
    public function set_attribute(string $key, mixed $value): static
    {
        $this->attributes[$key] = $this->to_storage($key, $value);

        return $this;
    }

    /**
     * Cast uygulanmış değer.
     */
    public function get_attribute(string $key): mixed
    {
        if (! array_key_exists($key, $this->attributes)) {
            return null;
        }

        return $this->from_storage($key, $this->attributes[$key]);
    }

    /**
     * Veritabanında saklanan (cast uygulanmamış) değer.
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
     * Query builder instance döndürür (soft delete açıksa silinmişler hariç)
     */
    public function query(): QueryBuilder
    {
        $builder = $this->db->table($this->table);

        return $this->soft_deletes ? $builder->where_null($this->deleted_at_column) : $builder;
    }

    /**
     * Soft delete ile silinmiş kayıtlar dahil query builder.
     */
    public function query_with_trashed(): QueryBuilder
    {
        return $this->db->table($this->table);
    }

    /**
     * Tüm kayıtları getirir (satır nesneleri; model örnekleri için get() kullanın)
     */
    public static function all(?Nsql $db = null): array
    {
        $instance = new static($db);
        return $instance->query()->get();
    }

    /**
     * Kayıtları model örnekleri olarak getirir.
     *
     * @param (callable(QueryBuilder): mixed)|null $scope Sorguyu daraltır: fn ($q) => $q->where(...)
     * @return list<static>
     */
    public static function get(?callable $scope = null, ?Nsql $db = null): array
    {
        $instance = new static($db);
        $builder = $instance->query();
        if ($scope !== null) {
            $scope($builder);
        }

        return static::hydrate($builder->get(), $instance->db);
    }

    /**
     * @param (callable(QueryBuilder): mixed)|null $scope
     */
    public static function first(?callable $scope = null, ?Nsql $db = null): ?static
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
     * ID'ye göre kayıt getirir
     */
    public static function find(int|string $id, ?Nsql $db = null): ?static
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
    public static function find_or_fail(int|string $id, ?Nsql $db = null): static
    {
        return static::find($id, $db) ?? throw new ModelNotFoundException(static::class, $id);
    }

    /**
     * Satırları (veritabanı değerleriyle) model örneklerine çevirir.
     *
     * @param iterable<object|array<string, mixed>> $rows
     * @return list<static>
     */
    public static function hydrate(iterable $rows, ?Nsql $db = null): array
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
     * Kayıt siler. Soft delete açıksa yalnızca deleted_at doldurulur.
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
     * Soft delete açık olsa bile kaydı kalıcı olarak siler.
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
     * Soft delete ile silinmiş kaydı geri alır.
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
     * Attribute getter (cast uygulanır); attribute yoksa tanımlı ilişki yüklenir.
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
     * Attribute setter (yalnızca fillable alanlar)
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
     * İlişkileri yükler (veya yeniden yükler).
     */
    public function load(string ...$relations): static
    {
        foreach ($relations as $relation) {
            if (! self::is_relation_method(static::class, $relation)) {
                throw new \InvalidArgumentException(static::class . "::{$relation}() bir ilişki metodu değil.");
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
     * Attribute'ları döndürür (hidden olanları hariç, veritabanı değerleriyle)
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
     * Cast uygulanmış attribute'lar ve yüklenmiş ilişkiler (hidden hariç).
     * datetime/date değerleri metin olarak döner.
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
     * Attribute'ları JSON olarak döndürür
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
     * Relationship: belongsTo. Varsayılan yabancı anahtar: ilişkili sınıfın snake_case adı + '_id'.
     *
     * @template T of Model
     * @param class-string<T> $related_class
     * @return T|null
     */
    protected function belongs_to(string $related_class, ?string $foreign_key = null, ?string $owner_key = null): ?Model
    {
        $foreign_key ??= Inflector::snake(self::short_name($related_class)) . '_id';
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
     * Relationship: hasOne. Varsayılan yabancı anahtar: bu sınıfın snake_case adı + '_id'.
     *
     * @template T of Model
     * @param class-string<T> $related_class
     * @return T|null
     */
    protected function has_one(string $related_class, ?string $foreign_key = null, ?string $local_key = null): ?Model
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
     * Relationship: hasMany. Varsayılan yabancı anahtar: bu sınıfın snake_case adı + '_id'.
     *
     * @template T of Model
     * @param class-string<T> $related_class
     * @param (callable(QueryBuilder): mixed)|null $scope Sıralama/filtre: fn ($q) => $q->order_by('id')
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

    private function related_query(Model $related, ?string $foreign_key, ?string $local_key): ?QueryBuilder
    {
        $foreign_key ??= Inflector::snake(self::short_name(static::class)) . '_id';
        $local_value = $this->attributes[$local_key ?? $this->primary_key] ?? null;

        return $local_value === null ? null : $related->query()->where($foreign_key, '=', $local_value);
    }

    private static function short_name(string $class): string
    {
        return substr((string) strrchr('\\' . $class, '\\'), 1);
    }

    /**
     * Alt sınıfta tanımlı, public, statik olmayan ve parametresiz metotlar ilişki sayılır.
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
