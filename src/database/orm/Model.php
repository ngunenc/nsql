<?php

namespace nsql\database\orm;

use nsql\database\Config;
use nsql\database\exceptions\QueryException;
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

    /**
     * save() INSERT/UPDATE kararı: true = yalnızca yüklenmiş/kaydedilmiş model UPDATE edilir
     * (elle atanmış UUID / doğal anahtarlı yeni model INSERT edilir); null = ORM_TRACK_EXISTS ayarı.
     */
    protected ?bool $track_exists = null;

    /** @var array<string, mixed> Veritabanından okunan / son kaydedilen değerler (dirty tracking) */
    private array $original = [];

    /** Satır veritabanında var mı (find/hydrate ile yüklendi veya save() ile eklendi) */
    private bool $exists = false;

    /** @var array<string, mixed> Yüklenmiş ilişkiler */
    private array $relations = [];

    /** @var array<string, array<string, bool>> */
    private static array $relation_methods = [];

    /** eager_load(): ilişki metodu sorgu çalıştırmak yerine tanımını kaydeder */
    private bool $capturing_relation = false;

    /** @var array{type: string, related: class-string<Model>, foreign_key: string, key: ?string, scope: ?callable}|null */
    private ?array $captured_relation = null;

    private const eager_chunk_size = 1000;

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
     * @param list<string> $with Toplu yüklenecek ilişkiler (bkz. eager_load())
     * @return list<static>
     */
    public static function get(?callable $scope = null, ?Nsql $db = null, array $with = []): array
    {
        $instance = new static($db);
        $builder = $instance->query();
        if ($scope !== null) {
            $scope($builder);
        }

        $models = static::hydrate($builder->get(), $instance->db);

        return $with === [] ? $models : static::eager_load($models, ...$with);
    }

    /**
     * @param (callable(QueryBuilder): mixed)|null $scope
     * @param list<string> $with Yüklenecek ilişkiler
     */
    public static function first(?callable $scope = null, ?Nsql $db = null, array $with = []): ?static
    {
        $instance = new static($db);
        $builder = $instance->query();
        if ($scope !== null) {
            $scope($builder);
        }
        $row = $builder->first();
        if ($row === null) {
            return null;
        }

        $model = $instance->new_from_row($row);

        return $with === [] ? $model : static::eager_load([$model], ...$with)[0];
    }

    /**
     * İlişkileri model listesine toplu yükler: ilişki başına tek `WHERE anahtar IN (...)` sorgusu
     * (N+1 yerine; 1000'den fazla anahtar parçalara bölünür). `belongs_to`, `has_one`, `has_many`
     * desteklenir; has_many scope'u (sıralama, filtre) toplu sorguya uygulanır, scope içindeki
     * LIMIT ise ebeveyn başına değil toplam sonuca uygulanır.
     *
     * @param list<static> $models
     * @return list<static>
     * @throws \InvalidArgumentException İlişki metodu yoksa veya bu yardımcılarla tanımlanmamışsa
     */
    public static function eager_load(array $models, string ...$relations): array
    {
        if ($models === []) {
            return $models;
        }
        foreach ($models as $model) {
            if (! $model instanceof static) {
                throw new \InvalidArgumentException('eager_load(): tüm modeller ' . static::class . ' olmalıdır.');
            }
        }

        $db = $models[0]->db;
        foreach ($relations as $relation) {
            $definition = $models[0]->relation_definition($relation);
            $related = new $definition['related']($db);

            if ($definition['type'] === 'belongs_to') {
                $owner_key = $definition['key'] ?? $related->primary_key;
                $by_key = [];
                foreach (self::fetch_related($related, $owner_key, self::key_values($models, $definition['foreign_key']), null) as $row) {
                    $by_key[(string) $row->{$owner_key}] ??= $related->new_from_row($row);
                }
                foreach ($models as $model) {
                    $value = $model->attributes[$definition['foreign_key']] ?? null;
                    $model->relations[$relation] = $value === null ? null : ($by_key[(string) $value] ?? null);
                }

                continue;
            }

            $local_key = $definition['key'] ?? $models[0]->primary_key;
            $foreign_key = $definition['foreign_key'];
            $groups = [];
            foreach (self::fetch_related($related, $foreign_key, self::key_values($models, $local_key), $definition['scope']) as $row) {
                $groups[(string) $row->{$foreign_key}][] = $related->new_from_row($row);
            }
            foreach ($models as $model) {
                $value = $model->attributes[$local_key] ?? null;
                $group = $value === null ? [] : ($groups[(string) $value] ?? []);
                $model->relations[$relation] = $definition['type'] === 'has_one' ? ($group[0] ?? null) : $group;
            }
        }

        return $models;
    }

    /**
     * @return array{type: string, related: class-string<Model>, foreign_key: string, key: ?string, scope: ?callable}
     */
    private function relation_definition(string $relation): array
    {
        if (! self::is_relation_method(static::class, $relation)) {
            throw new \InvalidArgumentException(static::class . "::{$relation}() bir ilişki metodu değil.");
        }

        $probe = new static($this->db);
        $probe->capturing_relation = true;
        try {
            $probe->{$relation}();
        } finally {
            $probe->capturing_relation = false;
        }

        return $probe->captured_relation ?? throw new \InvalidArgumentException(
            static::class . "::{$relation}() belongs_to/has_one/has_many ile tanımlanmadığı için toplu yüklenemez."
        );
    }

    /**
     * @param list<Model> $models
     * @return list<mixed>
     */
    private static function key_values(array $models, string $key): array
    {
        $values = [];
        foreach ($models as $model) {
            $value = $model->attributes[$key] ?? null;
            if ($value !== null) {
                $values[(string) $value] = $value;
            }
        }

        return array_values($values);
    }

    /**
     * @param list<mixed> $values
     * @return list<object>
     */
    private static function fetch_related(Model $related, string $column, array $values, ?callable $scope): array
    {
        $rows = [];
        foreach (array_chunk($values, self::eager_chunk_size) as $chunk) {
            $builder = $related->query()->where_in($column, $chunk);
            if ($scope !== null) {
                $scope($builder);
            }
            array_push($rows, ...$builder->get());
        }

        return $rows;
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

        return $result ? $instance->new_from_row($result) : null;
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
        $model->exists = true;
        $model->sync_original();

        return $model;
    }

    /**
     * Satır veritabanında var mı? (find/get/first/hydrate ile yüklenen veya save() ile eklenen model)
     */
    public function exists(): bool
    {
        return $this->exists;
    }

    /**
     * Mevcut değerleri "kaydedilmiş" olarak işaretler; sonraki get_dirty() boş döner.
     */
    public function sync_original(): static
    {
        $this->original = $this->attributes;

        return $this;
    }

    /**
     * Veritabanından okunan / son kaydedilen değer (cast uygulanmamış).
     */
    public function get_original(?string $key = null): mixed
    {
        return $key === null ? $this->original : ($this->original[$key] ?? null);
    }

    /**
     * Son yükleme / kayıttan beri değişen alanlar (veritabanı değerleriyle).
     *
     * @return array<string, mixed>
     */
    public function get_dirty(): array
    {
        $dirty = [];
        foreach ($this->attributes as $key => $value) {
            if (! array_key_exists($key, $this->original) || ! self::same_value($this->original[$key], $value)) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    /**
     * @param string|null $key Verilirse yalnızca o alan
     */
    public function is_dirty(?string $key = null): bool
    {
        $dirty = $this->get_dirty();

        return $key === null ? $dirty !== [] : array_key_exists($key, $dirty);
    }

    /**
     * Veritabanından gelen '5' ile atanan 5 (veya true ile 1) aynı değer sayılır.
     */
    private static function same_value(mixed $original, mixed $current): bool
    {
        if ($original === $current) {
            return true;
        }
        if ($original === null || $current === null) {
            return false;
        }

        $normalize = static fn (mixed $v): mixed => is_bool($v) ? (int) $v : $v;
        [$original, $current] = [$normalize($original), $normalize($current)];

        return is_scalar($original) && is_scalar($current)
            && is_numeric($original) && is_numeric($current)
            && (string) $original === (string) $current;
    }

    private function tracks_exists(): bool
    {
        return $this->track_exists ?? (bool) Config::get('orm_track_exists', Config::orm_track_exists);
    }

    /**
     * Kaydı ekler veya günceller. Hidden alanlar da kaydedilir.
     *
     * @throws \InvalidArgumentException Tablo veya kolon adı geçersizse
     */
    public function save(): bool
    {
        // Yüklenmiş model: UPDATE. Yüklenmemiş model: ORM_TRACK_EXISTS açıksa INSERT, kapalıysa
        // 2.x davranışı (birincil anahtar doluysa UPDATE).
        $is_new = ! $this->exists && ($this->tracks_exists() || empty($this->attributes[$this->primary_key]));

        // Yüklenmiş modelde yalnızca değişen kolonlar yazılır; değişiklik yoksa sorgu çalışmaz (#88)
        $data = $this->exists ? $this->get_dirty() : $this->attributes;
        if ($this->exists && $data === []) {
            return true;
        }

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
            // Birincil anahtar değiştirildiyse satır eski değeriyle bulunur
            $id = $this->exists && array_key_exists($this->primary_key, $this->original)
                ? $this->original[$this->primary_key]
                : $this->attributes[$this->primary_key];
            if (! $this->exists || self::same_value($id, $this->attributes[$this->primary_key] ?? null)) {
                unset($data[$this->primary_key]);
            }

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
            if ($result) {
                if ($this->timestamps) {
                    $this->attributes[$this->updated_at_column] = $data[$this->updated_at_column];
                }
                if ($this->exists) {
                    $this->sync_original();
                }
            }

            return $result;
        }

        // Elle atanmış birincil anahtar (UUID, doğal anahtar) INSERT'e dahil edilir
        $assigned_key = $this->attributes[$this->primary_key] ?? null;
        if (empty($assigned_key)) {
            unset($data[$this->primary_key]);
            $assigned_key = null;
        }

        if ($data === []) {
            throw new \InvalidArgumentException('Kaydedilecek alan yok.');
        }

        $columns = array_map(fn ($column) => $this->db->quote_identifier((string) $column), array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ") VALUES ({$placeholders})";

        if ($this->db->get_driver_name() === 'pgsql') {
            try {
                $id = $this->db->insert_returning(
                    $sql . ' RETURNING ' . $this->db->quote_identifier($this->primary_key),
                    array_values($data),
                    $this->primary_key
                );
            } catch (QueryException $e) {
                if ($this->db->throw_on_error()) {
                    throw $e;
                }

                return false;
            }
        } else {
            $id = $this->db->insert($sql, array_values($data));
            if ($id === false) {
                return false;
            }
        }

        // Elle atanmış anahtarda sürücünün son eklenen id'si (SQLite rowid, MySQL 0) kullanılmaz
        $this->attributes[$this->primary_key] = $assigned_key ?? $id;
        if ($this->timestamps) {
            $this->attributes[$this->created_at_column] = $data[$this->created_at_column];
            $this->attributes[$this->updated_at_column] = $data[$this->updated_at_column];
        }
        $this->exists = true;
        $this->sync_original();

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

        $deleted = $this->db->delete("DELETE FROM {$table} WHERE {$primary_key} = ?", [$id]) !== false;
        if ($deleted) {
            // Tekrar save() edilirse yeni kayıt olarak eklenir
            $this->exists = false;
        }

        return $deleted;
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
            if ($this->exists) {
                // Yazılan değer kaydedilmiş sayılır; sonraki save() onu tekrar yazmaz
                $this->original[$this->deleted_at_column] = $value;
            }
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
        if ($this->capturing_relation) {
            $this->captured_relation = [
                'type' => 'belongs_to',
                'related' => $related_class,
                'foreign_key' => $foreign_key,
                'key' => $owner_key,
                'scope' => null,
            ];

            return null;
        }
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
        if ($this->capture_relation('has_one', $related_class, $foreign_key, $local_key, null)) {
            return null;
        }
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
        if ($this->capture_relation('has_many', $related_class, $foreign_key, $local_key, $scope)) {
            return [];
        }
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

    /**
     * @param class-string<Model> $related_class
     */
    private function capture_relation(string $type, string $related_class, ?string $foreign_key, ?string $local_key, ?callable $scope): bool
    {
        if (! $this->capturing_relation) {
            return false;
        }
        $this->captured_relation = [
            'type' => $type,
            'related' => $related_class,
            'foreign_key' => $foreign_key ?? $this->default_foreign_key(),
            'key' => $local_key,
            'scope' => $scope,
        ];

        return true;
    }

    private function default_foreign_key(): string
    {
        return Inflector::snake(self::short_name(static::class)) . '_id';
    }

    private function related_query(Model $related, ?string $foreign_key, ?string $local_key): ?QueryBuilder
    {
        $foreign_key ??= $this->default_foreign_key();
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
        if (str_starts_with($type, 'decimal:')) {
            return self::to_decimal($key, $value, $type);
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
        if (str_starts_with($type, 'decimal:')) {
            return self::to_decimal($key, $value, $type);
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

    /**
     * `decimal:N` cast'i: değeri N ondalık basamaklı string'e yuvarlar (yarım yukarı). Ondalık metin
     * float'a çevrilmeden basamak basamak işlenir; para / oran değerlerinde hassasiyet kaybı olmaz (#108).
     */
    private static function to_decimal(string $key, mixed $value, string $type): string
    {
        $scale = substr($type, strlen('decimal:'));
        if (! ctype_digit($scale)) {
            throw new \InvalidArgumentException(static::class . ": geçersiz cast '{$type}' ({$key}); örn. 'decimal:2'");
        }
        $scale = (int) $scale;

        $text = match (true) {
            is_int($value) => (string) $value,
            is_float($value) => sprintf('%.' . ($scale + 1) . 'F', $value),
            is_string($value) => trim($value),
            default => throw new \InvalidArgumentException(static::class . ": '{$key}' decimal değeri olmalıdır."),
        };
        if (! preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $text, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
            if (! is_numeric($text)) {
                throw new \InvalidArgumentException(static::class . ": '{$key}' decimal değeri olmalıdır: " . substr($text, 0, 32));
            }
            // Üslü gösterim (1e3): float üzerinden
            return self::to_decimal($key, (float) $text, $type);
        }

        $fraction = str_pad($m[3] ?? '', $scale + 1, '0');
        $digits = ($m[2] === '' ? '0' : $m[2]) . substr($fraction, 0, $scale);
        if ($fraction[$scale] >= '5') {
            // Basamak dizisine 1 ekle (elde ile)
            for ($i = strlen($digits) - 1; $i >= 0; $i--) {
                if ($digits[$i] !== '9') {
                    $digits[$i] = (string) ((int) $digits[$i] + 1);
                    break;
                }
                $digits[$i] = '0';
            }
            if ($i < 0) {
                $digits = '1' . $digits;
            }
        }

        $integer = ltrim(substr($digits, 0, strlen($digits) - $scale), '0');
        $integer = $integer === '' ? '0' : $integer;
        $result = $scale > 0 ? $integer . '.' . substr($digits, -$scale) : $integer;

        return $m[1] === '-' && trim($digits, '0') !== '' ? '-' . $result : $result;
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
