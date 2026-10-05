<?php

namespace Tests\Unit;

use nsql\database\cache\CacheManager;
use nsql\database\cache\InMemoryAdapter;
use nsql\database\cache\MemcachedAdapter;
use nsql\database\cache\RedisAdapter;
use PHPUnit\Framework\TestCase;

class CacheAdapterSmokeTest extends TestCase
{
    public function test_in_memory_adapter_roundtrip_and_tags(): void
    {
        $adapter = new InMemoryAdapter(60, 50);
        $this->assertTrue($adapter->is_available());
        $this->assertSame('in_memory', $adapter->get_name());

        $this->assertTrue($adapter->set('k1', ['a' => 1], 60, ['t1']));
        $this->assertTrue($adapter->has('k1'));
        $this->assertSame(['a' => 1], $adapter->get('k1'));

        $this->assertTrue($adapter->invalidate_by_tag('t1'));
        $this->assertNull($adapter->get('k1'));
    }

    public function test_cache_manager_uses_in_memory_fallback(): void
    {
        $manager = new CacheManager(
            new InMemoryAdapter(60, 20),
            null,
            false
        );

        $this->assertTrue($manager->set('m1', 'value'));
        $this->assertSame('value', $manager->get('m1'));
        $this->assertTrue($manager->delete('m1'));
        $this->assertNull($manager->get('m1'));
    }

    public function test_redis_adapter_reports_availability_without_extension(): void
    {
        $adapter = new RedisAdapter();
        if (! extension_loaded('redis')) {
            $this->assertFalse($adapter->is_available());
            $this->assertNull($adapter->get('x'));
            $this->assertFalse($adapter->set('x', 1));
            return;
        }

        $this->assertSame('redis', $adapter->get_name());
        if (! $adapter->is_available()) {
            $this->markTestSkipped('Redis extension loaded but server unavailable');
        }

        $this->assertTrue($adapter->set('nsql_smoke', ['ok' => true], 5));
        $this->assertSame(['ok' => true], $adapter->get('nsql_smoke'));
        $this->assertTrue($adapter->delete('nsql_smoke'));
    }

    public function test_memcached_adapter_reports_availability_without_extension(): void
    {
        $adapter = new MemcachedAdapter();
        if (! extension_loaded('memcached')) {
            $this->assertFalse($adapter->is_available());
            $this->assertNull($adapter->get('x'));
            $this->assertFalse($adapter->set('x', 1));
            return;
        }

        $this->assertSame('memcached', $adapter->get_name());
        if (! $adapter->is_available()) {
            $this->markTestSkipped('Memcached extension loaded but server unavailable');
        }

        $this->assertTrue($adapter->set('nsql_smoke', 'v', 5));
        $this->assertSame('v', $adapter->get('nsql_smoke'));
        $this->assertTrue($adapter->delete('nsql_smoke'));
    }
}
