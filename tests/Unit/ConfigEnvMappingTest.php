<?php

namespace Tests\Unit;

use nsql\database\config;
use PHPUnit\Framework\TestCase;

class ConfigEnvMappingTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        $this->tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_config_test_' . uniqid('', true);
        mkdir($this->tempRoot, 0700, true);
        $this->clear_pool_env();
        config::set_project_root($this->tempRoot);
        config::refresh();
    }

    protected function tearDown(): void
    {
        $this->clear_pool_env();
        config::set_project_root(dirname(__DIR__, 2));
        config::refresh();

        $envFile = $this->tempRoot . DIRECTORY_SEPARATOR . '.env';
        if (is_file($envFile)) {
            unlink($envFile);
        }
        if (is_dir($this->tempRoot)) {
            @rmdir($this->tempRoot);
        }
    }

    private function clear_pool_env(): void
    {
        foreach ([
            'DB_MIN_CONNECTIONS',
            'MIN_CONNECTIONS',
            'DB_MAX_CONNECTIONS',
            'MAX_CONNECTIONS',
            'DB_HEALTH_CHECK_INTERVAL',
            'HEALTH_CHECK_INTERVAL',
            'DB_CONNECTION_TIMEOUT',
            'CONNECTION_TIMEOUT',
            'DB_HOST',
        ] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    private function write_env(string $contents): void
    {
        file_put_contents($this->tempRoot . DIRECTORY_SEPARATOR . '.env', $contents);
        config::refresh();
    }

    public function test_db_min_connections_alias_maps_to_min_connections(): void
    {
        $this->write_env("DB_MIN_CONNECTIONS=7\n");

        $this->assertSame(7, config::get('min_connections'));
        $this->assertSame(7, config::get('DB_MIN_CONNECTIONS'));
        $this->assertTrue(config::has('min_connections'));
    }

    public function test_min_connections_canonical_key(): void
    {
        $this->write_env("MIN_CONNECTIONS=4\n");

        $this->assertSame(4, config::get('min_connections'));
        $this->assertSame(4, config::get('DB_MIN_CONNECTIONS'));
    }

    public function test_pool_defaults_match_class_constants_when_unset(): void
    {
        config::refresh();

        $this->assertSame(config::min_connections, config::get('min_connections'));
        $this->assertSame(config::max_connections, config::get('max_connections'));
        $this->assertSame(config::health_check_interval, config::get('health_check_interval'));
        $this->assertSame(config::connection_idle_timeout, config::get('connection_idle_timeout'));
        $this->assertSame(config::connection_timeout, config::get('connection_timeout'));
    }

    public function test_default_values_is_single_source_of_truth(): void
    {
        $defaults = config::default_values();

        $this->assertSame(config::min_connections, $defaults['MIN_CONNECTIONS']);
        $this->assertSame(config::max_connections, $defaults['MAX_CONNECTIONS']);
        $this->assertSame(config::health_check_interval, $defaults['HEALTH_CHECK_INTERVAL']);
        $this->assertSame(config::connection_idle_timeout, $defaults['CONNECTION_IDLE_TIMEOUT']);
        $this->assertSame(config::connection_timeout, $defaults['CONNECTION_TIMEOUT']);
        $this->assertSame(config::query_cache_timeout, $defaults['QUERY_CACHE_TIMEOUT']);
        $this->assertSame(config::statement_cache_limit, $defaults['STATEMENT_CACHE_LIMIT']);
        $this->assertSame(config::min_chunk_size, $defaults['MIN_CHUNK_SIZE']);
        $this->assertSame(config::max_chunk_size, $defaults['MAX_CHUNK_SIZE']);
    }

    public function test_call_site_fallbacks_match_constants(): void
    {
        // nsql / connection_trait / pool initialize fallback'ları
        $this->assertSame(2, config::min_connections);
        $this->assertSame(15, config::max_connections);
        $this->assertSame(
            config::get('max_connections', config::max_connections),
            config::max_connections
        );
    }

    public function test_db_host_lowercase_lookup(): void
    {
        $this->write_env("DB_HOST=config-test-host\n");

        $this->assertSame('config-test-host', config::get('db_host'));
    }

    public function test_set_uses_canonical_uppercase_key(): void
    {
        config::set('min_connections', 9);
        $this->assertSame(9, config::get('MIN_CONNECTIONS'));
        $this->assertSame(9, config::get('db_min_connections'));
    }
}
