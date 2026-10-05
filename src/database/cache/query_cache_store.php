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
 * Store hataları sorguyu bozmaz: okuma hatası miss, yazma hatası no-op sayılır.
 */
final class query_cache_store
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
     * @return array{data: mixed, time: int, tags: list<string>, tables: list<string>}|null
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
            if ($this->versions($tables, $tags) !== $stored['versions']) {
                return null;
            }

            return ['data' => $entry['data'] ?? null, 'time' => $entry['time'], 'tags' => $tags, 'tables' => $tables];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array{data: mixed, time: int, tags: list<string>, tables: list<string>} $entry
     */
    public function put(string $key, array $entry, int $ttl): void
    {
        try {
            // Kayıt store'a string olarak yazılır: JSON tabanlı arka uçlarda (redis_adapter) da
            // satırlar stdClass olarak geri döner
            $this->cache->set($this->entry_key($key), [
                'payload' => serialize($entry),
                'versions' => $this->versions($entry['tables'], $entry['tags']),
            ], max(1, $ttl));
        } catch (\Throwable) {
        }
    }

    /**
     * @param list<string> $tables
     */
    public function invalidate_tables(array $tables): void
    {
        $this->bump(array_map(fn (string $t) => $this->version_key('t', $t), $tables));
    }

    /**
     * @param list<string> $tags
     */
    public function invalidate_tags(array $tags): void
    {
        $this->bump(array_map(fn (string $t) => $this->version_key('g', $t), $tags));
    }

    public function invalidate_all(): void
    {
        $this->bump([$this->version_key('a', self::ALL_SCOPE)]);
    }

    /**
     * @param list<string> $keys
     */
    private function bump(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        try {
            $token = bin2hex(random_bytes(8));
            $this->cache->setMultiple(array_fill_keys($keys, $token));
        } catch (\Throwable) {
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
