<?php

namespace Tests\Unit;

use nsql\database\cache\AdapterSimpleCache;
use nsql\database\cache\InMemoryAdapter;
use nsql\database\cache\QueryCacheStoreFactory;
use nsql\database\Config;
use PHPUnit\Framework\TestCase;

class AdapterSimpleCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::set('query_cache_driver', Config::query_cache_driver);
        Config::set('redis_port', 6379);
        QueryCacheStoreFactory::reset();
    }

    public function test_get_set_delete_and_default(): void
    {
        $cache = new AdapterSimpleCache(new InMemoryAdapter());

        $this->assertSame('fallback', $cache->get('missing', 'fallback'));
        $this->assertTrue($cache->set('k', ['a' => 1]));
        $this->assertSame(['a' => 1], $cache->get('k'));
        $this->assertTrue($cache->has('k'));
        $cache->delete('k');
        $this->assertFalse($cache->has('k'));
    }

    public function test_non_positive_ttl_deletes(): void
    {
        $cache = new AdapterSimpleCache(new InMemoryAdapter());
        $cache->set('k', 'v');

        $cache->set('k', 'v2', 0);

        $this->assertNull($cache->get('k'));
    }

    public function test_multiple_and_date_interval(): void
    {
        $cache = new AdapterSimpleCache(new InMemoryAdapter());

        $this->assertTrue($cache->setMultiple(['a' => 1, 'b' => 2], new \DateInterval('PT60S')));
        $this->assertSame(['a' => 1, 'b' => 2, 'c' => null], $cache->getMultiple(['a', 'b', 'c']));
        $cache->deleteMultiple(['a', 'b']);
        $this->assertSame(['a' => 0, 'b' => 0], $cache->getMultiple(['a', 'b'], 0));
    }

    public function test_factory_memory_driver_has_no_store(): void
    {
        Config::set('query_cache_driver', 'memory');

        $this->assertNull(QueryCacheStoreFactory::from_config(fn () => $this->fail('uyarı beklenmiyor')));
    }

    public function test_factory_warns_on_invalid_driver(): void
    {
        Config::set('query_cache_driver', 'mongo');
        $warnings = [];

        $this->assertNull(QueryCacheStoreFactory::from_config(function (string $m) use (&$warnings): void {
            $warnings[] = $m;
        }));
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString("'mongo'", $warnings[0]);
    }

    public function test_factory_warns_when_redis_unreachable(): void
    {
        Config::set('query_cache_driver', 'redis');
        Config::set('redis_port', 1);
        $warnings = [];

        $this->assertNull(QueryCacheStoreFactory::from_config(function (string $m) use (&$warnings): void {
            $warnings[] = $m;
        }));
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('QUERY_CACHE_DRIVER=redis', $warnings[0]);

        // Aynı yapılandırma süreç içinde tekrar denenmez
        QueryCacheStoreFactory::from_config(fn () => $this->fail('ikinci uyarı beklenmiyor'));
    }

    public function test_cache_driver_alias(): void
    {
        Config::set('cache_driver', 'memcached');

        $this->assertSame('memcached', Config::get('query_cache_driver'));
    }
}
