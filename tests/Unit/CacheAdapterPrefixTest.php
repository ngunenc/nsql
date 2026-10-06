<?php

namespace Tests\Unit;

use nsql\database\cache\MemcachedAdapter;
use nsql\database\cache\RedisAdapter;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMemcached;
use Tests\Support\FakeRedis;

class CacheAdapterPrefixTest extends TestCase
{
    public function test_redis_keys_are_prefixed(): void
    {
        $redis = new FakeRedis();
        $adapter = new RedisAdapter(prefix: 'app1_', client: $redis);

        $this->assertTrue($adapter->set('k', ['a' => 1], 60, ['t']));
        $this->assertArrayHasKey('app1_k', $redis->data);
        $this->assertArrayHasKey('app1_tag:t', $redis->data);
        $this->assertSame(['a' => 1], $adapter->get('k'));
        $this->assertTrue($adapter->has('k'));
    }

    public function test_redis_clear_only_deletes_own_prefix(): void
    {
        $redis = new FakeRedis();
        $redis->data = ['other_app:session' => 's', 'nsql_x' => 'foreign-looking-but-other-prefix'];
        $adapter = new RedisAdapter(prefix: 'app1_', client: $redis);
        foreach (['a', 'b', 'c', 'd', 'e'] as $key) {
            $adapter->set($key, $key, 60, ['t']);
        }

        $this->assertTrue($adapter->clear());

        $this->assertSame(['other_app:session', 'nsql_x'], array_keys($redis->data));
        $this->assertNull($adapter->get('a'));
    }

    public function test_redis_clear_escapes_glob_characters_in_prefix(): void
    {
        $redis = new FakeRedis();
        $redis->data = ['axb_k' => 'keep'];
        $adapter = new RedisAdapter(prefix: 'a?b_', client: $redis);
        $adapter->set('k', 'mine', 60);

        $adapter->clear();

        $this->assertSame(['axb_k'], array_keys($redis->data));
    }

    public function test_redis_tag_invalidation_stays_in_prefix(): void
    {
        $redis = new FakeRedis();
        $adapter = new RedisAdapter(prefix: 'app1_', client: $redis);
        $adapter->set('k1', 1, 60, ['users']);
        $adapter->set('k2', 2, 60);

        $this->assertTrue($adapter->invalidate_by_tag('users'));

        $this->assertNull($adapter->get('k1'));
        $this->assertSame(2, $adapter->get('k2'));
    }

    public function test_empty_prefix_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RedisAdapter(prefix: '');
    }

    public function test_memcached_clear_does_not_flush_server(): void
    {
        $memcached = new FakeMemcached();
        $memcached->data = ['other_app:session' => 's'];
        $adapter = new MemcachedAdapter(prefix: 'app1_', client: $memcached);

        $this->assertTrue($adapter->set('k', 'v', 60));
        $this->assertSame('v', $adapter->get('k'));
        $this->assertTrue($adapter->has('k'));

        $this->assertTrue($adapter->clear());

        $this->assertNull($adapter->get('k'));
        $this->assertFalse($adapter->has('k'));
        $this->assertSame('s', $memcached->data['other_app:session']);
    }

    public function test_memcached_evicted_generation_does_not_resurrect_old_entries(): void
    {
        $memcached = new FakeMemcached();
        $adapter = new MemcachedAdapter(prefix: 'app1_', client: $memcached);
        $adapter->set('k', 'old', 60);

        unset($memcached->data['app1_ns']);

        $this->assertNull($adapter->get('k'));
    }

    public function test_memcached_long_and_whitespace_keys_are_hashed(): void
    {
        $adapter = new MemcachedAdapter(prefix: 'app1_', client: new FakeMemcached());

        $this->assertTrue($adapter->set(str_repeat('x', 300), 'long', 60));
        $this->assertSame('long', $adapter->get(str_repeat('x', 300)));
        $this->assertTrue($adapter->set('a b', 'space', 60));
        $this->assertSame('space', $adapter->get('a b'));
    }

    public function test_memcached_tag_invalidation(): void
    {
        $adapter = new MemcachedAdapter(prefix: 'app1_', client: new FakeMemcached());
        $adapter->set('k1', 1, 60, ['users']);
        $adapter->set('k2', 2, 60);

        $this->assertTrue($adapter->invalidate_by_tag(['users']));

        $this->assertNull($adapter->get('k1'));
        $this->assertSame(2, $adapter->get('k2'));
    }
}
