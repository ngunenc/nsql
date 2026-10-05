<?php

namespace Tests\Unit;

use nsql\database\Config;
use PHPUnit\Framework\TestCase;

class ConfigEnvMappingTest extends TestCase
{
    private const ENV_KEYS = [
        'DB_MIN_CONNECTIONS',
        'MIN_CONNECTIONS',
        'DB_MAX_CONNECTIONS',
        'MAX_CONNECTIONS',
        'DB_HEALTH_CHECK_INTERVAL',
        'HEALTH_CHECK_INTERVAL',
        'DB_CONNECTION_TIMEOUT',
        'CONNECTION_TIMEOUT',
        'DB_HOST',
    ];

    private string $tempRoot;

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        $this->tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_config_test_' . uniqid('', true);
        mkdir($this->tempRoot, 0700, true);
        foreach (self::ENV_KEYS as $key) {
            $this->savedEnv[$key] = getenv($key);
        }
        $this->clear_pool_env();
        Config::set_project_root($this->tempRoot);
        Config::refresh();
    }

    protected function tearDown(): void
    {
        $this->clear_pool_env();
        foreach ($this->savedEnv as $key => $value) {
            if ($value !== false) {
                putenv($key . '=' . $value);
            }
        }
        Config::set_project_root(dirname(__DIR__, 2));
        Config::refresh();

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
        foreach (self::ENV_KEYS as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    private function write_env(string $contents): void
    {
        file_put_contents($this->tempRoot . DIRECTORY_SEPARATOR . '.env', $contents);
        Config::refresh();
    }

    public function test_db_min_connections_alias_maps_to_min_connections(): void
    {
        $this->write_env("DB_MIN_CONNECTIONS=7\n");

        $this->assertSame(7, Config::get('min_connections'));
        $this->assertSame(7, Config::get('DB_MIN_CONNECTIONS'));
        $this->assertTrue(Config::has('min_connections'));
    }

    public function test_min_connections_canonical_key(): void
    {
        $this->write_env("MIN_CONNECTIONS=4\n");

        $this->assertSame(4, Config::get('min_connections'));
        $this->assertSame(4, Config::get('DB_MIN_CONNECTIONS'));
    }

    public function test_pool_defaults_match_class_constants_when_unset(): void
    {
        Config::refresh();

        $this->assertSame(Config::min_connections, Config::get('min_connections'));
        $this->assertSame(Config::max_connections, Config::get('max_connections'));
        $this->assertSame(Config::health_check_interval, Config::get('health_check_interval'));
        $this->assertSame(Config::connection_idle_timeout, Config::get('connection_idle_timeout'));
        $this->assertSame(Config::connection_timeout, Config::get('connection_timeout'));
    }

    public function test_default_values_is_single_source_of_truth(): void
    {
        $defaults = Config::default_values();

        $this->assertSame(Config::min_connections, $defaults['MIN_CONNECTIONS']);
        $this->assertSame(Config::max_connections, $defaults['MAX_CONNECTIONS']);
        $this->assertSame(Config::health_check_interval, $defaults['HEALTH_CHECK_INTERVAL']);
        $this->assertSame(Config::connection_idle_timeout, $defaults['CONNECTION_IDLE_TIMEOUT']);
        $this->assertSame(Config::connection_timeout, $defaults['CONNECTION_TIMEOUT']);
        $this->assertSame(Config::query_cache_timeout, $defaults['QUERY_CACHE_TIMEOUT']);
        $this->assertSame(Config::statement_cache_limit, $defaults['STATEMENT_CACHE_LIMIT']);
        $this->assertSame(Config::min_chunk_size, $defaults['MIN_CHUNK_SIZE']);
        $this->assertSame(Config::max_chunk_size, $defaults['MAX_CHUNK_SIZE']);
    }

    public function test_call_site_fallbacks_match_constants(): void
    {
        // nsql / connection_trait / pool initialize fallback'ları
        $this->assertSame(2, Config::min_connections);
        $this->assertSame(15, Config::max_connections);
        $this->assertSame(
            Config::get('max_connections', Config::max_connections),
            Config::max_connections
        );
    }

    public function test_db_host_lowercase_lookup(): void
    {
        $this->write_env("DB_HOST=config-test-host\n");

        $this->assertSame('config-test-host', Config::get('db_host'));
    }

    public function test_set_uses_canonical_uppercase_key(): void
    {
        Config::set('min_connections', 9);
        $this->assertSame(9, Config::get('MIN_CONNECTIONS'));
        $this->assertSame(9, Config::get('db_min_connections'));
    }

    public function test_set_before_bootstrap_is_not_overwritten_by_env_file(): void
    {
        file_put_contents($this->tempRoot . DIRECTORY_SEPARATOR . '.env', "QUERY_CACHE_ENABLED=true\n");
        Config::set_project_root($this->tempRoot);

        Config::set('query_cache_enabled', false);

        $this->assertFalse(Config::get('query_cache_enabled'));
    }
}
