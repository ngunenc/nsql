<?php

namespace Tests\Unit;

use nsql\database\Config;
use nsql\database\traits\CacheTrait;
use PHPUnit\Framework\TestCase;

class QueryCacheInternalsTest extends TestCase
{
    private mixed $cleanup_probability;

    protected function setUp(): void
    {
        $this->cleanup_probability = Config::get('cache_cleanup_probability', 10);
        Config::set('cache_cleanup_probability', 0);
    }

    protected function tearDown(): void
    {
        Config::set('cache_cleanup_probability', $this->cleanup_probability);
    }

    private function cache(int $limit = 100, int $timeout = 3600): object
    {
        return new class ($limit, $timeout) {
            use CacheTrait;

            public function __construct(int $limit, int $timeout)
            {
                $this->query_cache_enabled = true;
                $this->query_cache_size_limit = $limit;
                $this->query_cache_timeout = $timeout;
            }

            public function get_transaction_level(): int
            {
                return 0;
            }

            public function put(string $key, mixed $data, array $tables, array $tags = []): bool
            {
                return $this->add_to_query_cache($key, $data, $tags, $tables);
            }

            public function get(string $key): mixed
            {
                return $this->get_from_query_cache($key);
            }

            public function backdate(string $key, int $seconds): void
            {
                $this->query_cache[$key]['time'] -= $seconds;
            }

            public function purge(): void
            {
                $this->purge_expired_cache();
            }

            /** @return array{keys: list<string>, tables: int, table_keys: int, tags: int, tag_keys: int} */
            public function internals(): array
            {
                return [
                    'keys' => array_keys($this->query_cache),
                    'tables' => count($this->table_to_keys),
                    'table_keys' => array_sum(array_map('count', $this->table_to_keys)),
                    'tags' => count($this->tag_to_keys),
                    'tag_keys' => array_sum(array_map('count', $this->tag_to_keys)),
                ];
            }
        };
    }

    public function test_mappings_stay_bounded_after_many_queries(): void
    {
        $cache = $this->cache(100);

        for ($i = 0; $i < 10000; $i++) {
            $cache->put("k{$i}", $i, ['t' . ($i % 500)], ['tag' . ($i % 300)]);
        }

        $state = $cache->internals();
        $this->assertCount(100, $state['keys']);
        $this->assertSame(100, $state['table_keys']);
        $this->assertSame(100, $state['tag_keys']);
        $this->assertLessThanOrEqual(100, $state['tables']);
        $this->assertLessThanOrEqual(100, $state['tags']);
    }

    public function test_lru_evicts_least_recently_used(): void
    {
        $cache = $this->cache(3);
        $cache->put('a', 1, ['t']);
        $cache->put('b', 2, ['t']);
        $cache->put('c', 3, ['t']);

        $this->assertSame(1, $cache->get('a'));
        $cache->put('d', 4, ['t']);

        $this->assertNull($cache->get('b'));
        $this->assertSame(['c', 'a', 'd'], $cache->internals()['keys']);
    }

    public function test_table_and_tag_invalidation_clean_mappings(): void
    {
        $cache = $this->cache();
        $cache->put('a', 1, ['users'], ['x']);
        $cache->put('b', 2, ['users', 'orders'], ['y']);
        $cache->put('c', 3, ['orders'], ['x']);

        $cache->invalidate_cache_by_table('USERS');
        $this->assertSame(['c'], $cache->internals()['keys']);

        $cache->invalidate_cache_by_tag('x');
        $state = $cache->internals();
        $this->assertSame([], $state['keys']);
        $this->assertSame(0, $state['tables']);
        $this->assertSame(0, $state['tags']);
    }

    public function test_expired_entries_are_removed_with_mappings(): void
    {
        $cache = $this->cache(100, 60);
        $cache->put('old', 1, ['users'], ['x']);
        $cache->put('new', 2, ['orders']);
        $cache->backdate('old', 120);

        $cache->purge();

        $state = $cache->internals();
        $this->assertSame(['new'], $state['keys']);
        $this->assertSame(1, $state['tables']);
        $this->assertSame(0, $state['tags']);
    }

    public function test_per_table_ttl_applies_on_read(): void
    {
        $cache = $this->cache(100, 3600);
        $cache->set_table_ttl('users', 10);
        $cache->put('a', 1, ['users']);
        $cache->backdate('a', 30);

        $this->assertNull($cache->get('a'));
        $this->assertSame(0, $cache->internals()['tables']);
    }

    public function test_unknown_tables_are_not_cached(): void
    {
        $cache = $this->cache();
        $this->assertFalse($cache->put('a', 1, []));
        $this->assertSame([], $cache->internals()['keys']);
    }

    public function test_no_lock_file_is_created(): void
    {
        $lock = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_cache.lock';
        if (is_file($lock)) {
            @unlink($lock);
        }

        $cache = $this->cache();
        $cache->put('a', 1, ['users'], ['x']);
        $cache->invalidate_cache_by_table('users');
        $cache->invalidate_cache_by_tag('x');
        $cache->invalidate_all_cache();

        $this->assertFileDoesNotExist($lock);
    }
}
