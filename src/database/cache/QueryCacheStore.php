<?php

namespace nsql\database\cache;

use Psr\SimpleCache\CacheInterface;

/**
 * Query cache için paylaşılan (süreçler arası) PSR-16 arka ucu.
 *
 * PSR-16'da tag veya prefix ile silme olmadığından geçersiz kılma sürüm token'larıyla yapılır:
 * her tablo/tag ve global kapsam için store'da rastgele bir token tutulur. Kayıt yazılırken
 * ilgili token'ların anlık görüntüsü kayda eklenir; okumada güncel token'lar farklıysa kayıt
 * bayattır ve yok sayılır. Token anahtarları TTL'siz yazılır.
 *
 * Store hataları sorguyu bozmaz: okuma hatası miss, kayıt yazma hatası no-op sayılır. Geçersiz kılma
 * hatası ise false döner; çağıran (CacheTrait) bunu bekletip tekrar dener.
 */
final class QueryCacheStore
{
    private const ALL_SCOPE = 'all';

    public function __construct(
        private CacheInterface $cache,
        private string $prefix = 'nsql_qc_'
    ) {
    }

    public function cache(): CacheInterface
    {
        return $this->cache;
    }

    /**
     * @return array{data: mixed, time: int, tags: list<string>, tables: list<string>, versions: array<string, mixed>}|null
     */
    public function get(string $key): ?array
    {
        try {
            $stored = $this->cache->get($this->entry_key($key));
            if (! is_array($stored) || ! is_string($stored['payload'] ?? null) || ! isset($stored['versions'])) {
                return null;
            }

            // Satırlar stdClass; başka sınıf örneklenmez (paylaşılan store'dan object injection yok)
            $entry = unserialize($stored['payload'], ['allowed_classes' => [\stdClass::class]]);
            if (! is_array($entry)) {
                return null;
            }
            if (! is_int($entry['time'] ?? null) || ! is_array($entry['tables'] ?? null) || ! is_array($entry['tags'] ?? null)) {
                return null;
            }

            $tables = array_values(array_map('strval', $entry['tables']));
            $tags = array_values(array_map('strval', $entry['tags']));
            $versions = $this->versions($tables, $tags);
            if ($versions !== $stored['versions']) {
                return null;
            }

            return ['data' => $entry['data'] ?? null, 'time' => $entry['time'], 'tags' => $tags, 'tables' => $tables, 'versions' => $versions];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Kaydı yazar; yazılan sürüm görüntüsünü döndürür (store hatasında null).
     *
     * @param array{data: mixed, time: int, tags: list<string>, tables: list<string>} $entry
     * @return array<string, mixed>|null
     */
    public function put(string $key, array $entry, int $ttl): ?array
    {
        try {
            $versions = $this->versions($entry['tables'], $entry['tags']);
            // Kayıt store'a string olarak yazılır: JSON tabanlı arka uçlarda (redis_adapter) da
            // satırlar stdClass olarak geri döner
            $this->cache->set($this->entry_key($key), [
                'payload' => serialize([
                    'data' => $entry['data'],
                    'time' => $entry['time'],
                    'tags' => $entry['tags'],
                    'tables' => $entry['tables'],
                ]),
                'versions' => $versions,
            ], max(1, $ttl));

            return $versions;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Süreç içi kaydın sürüm görüntüsü hâlâ güncel mi? Başka bir süreç ilgili tablo/tag'i veya
     * tüm cache'i geçersiz kıldıysa false (#103). Store okunamazsa güvenli tarafta false.
     *
     * @param list<string> $tables
     * @param list<string> $tags
     * @param array<string, mixed> $versions
     */
    public function is_current(array $tables, array $tags, array $versions): bool
    {
        try {
            return $this->versions($tables, $tags) === $versions;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param list<string> $tables
     * @return bool Store'a yazılamadıysa false (çağıran tekrar denemeli)
     */
    public function invalidate_tables(array $tables): bool
    {
        return $this->bump(array_map(fn (string $t) => $this->version_key('t', $t), $tables));
    }

    /**
     * @param list<string> $tags
     */
    public function invalidate_tags(array $tags): bool
    {
        return $this->bump(array_map(fn (string $t) => $this->version_key('g', $t), $tags));
    }

    public function invalidate_all(): bool
    {
        return $this->bump([$this->version_key('a', self::ALL_SCOPE)]);
    }

    /**
     * @param list<string> $keys
     */
    private function bump(array $keys): bool
    {
        if ($keys === []) {
            return true;
        }

        try {
            $token = bin2hex(random_bytes(8));

            return $this->cache->setMultiple(array_fill_keys($keys, $token));
        } catch (\Throwable) {
            // Hata yutulmaz: geçersiz kılma kaybolursa diğer süreçler TTL dolana kadar bayat veri okur
            return false;
        }
    }

    /**
     * @param list<string> $tables
     * @param list<string> $tags
     * @return array<string, mixed>
     */
    private function versions(array $tables, array $tags): array
    {
        $keys = [$this->version_key('a', self::ALL_SCOPE)];
        foreach ($tables as $table) {
            $keys[] = $this->version_key('t', $table);
        }
        foreach ($tags as $tag) {
            $keys[] = $this->version_key('g', $tag);
        }

        $values = $this->cache->getMultiple($keys);
        $versions = is_array($values) ? $values : iterator_to_array($values);

        // Eksik token null bırakılmaz: null anlık görüntülü bir kayıt, sonradan oluşan token tahliye
        // edilince yeniden "güncel" sayılırdı. Yeni token her zaman farklıdır.
        $missing = array_keys(array_filter($versions, static fn ($v) => $v === null));
        if ($missing !== []) {
            $created = [];
            foreach ($missing as $key) {
                $created[$key] = bin2hex(random_bytes(8));
            }
            if (! $this->cache->setMultiple($created)) {
                throw new \RuntimeException('Query cache sürüm token\'ı yazılamadı');
            }
            $versions = $created + $versions;
        }
        ksort($versions);

        return $versions;
    }

    private function entry_key(string $key): string
    {
        return $this->prefix . 'e_' . $key;
    }

    private function version_key(string $kind, string $name): string
    {
        return $this->prefix . 'v' . $kind . '_' . md5($name);
    }
}
