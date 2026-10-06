<?php

namespace Tests\Unit;

use nsql\database\Config;
use nsql\security\KeyManager;
use PHPUnit\Framework\TestCase;

class KeyManagerTest extends TestCase
{
    private string|false $env_key;
    private string $storage;

    protected function setUp(): void
    {
        $this->env_key = getenv('ENCRYPTION_KEY');
        putenv('ENCRYPTION_KEY');
        Config::set_project_root(dirname(__DIR__, 2));
        Config::set('encryption_key', null);
        $this->storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_key_' . bin2hex(random_bytes(4)) . DIRECTORY_SEPARATOR . 'encryption.key';
        Config::set('encryption_key_storage', $this->storage);
    }

    protected function tearDown(): void
    {
        if (is_file($this->storage)) {
            unlink($this->storage);
            rmdir(dirname($this->storage));
        }
        if ($this->env_key !== false) {
            putenv('ENCRYPTION_KEY=' . $this->env_key);
        }
        Config::set('encryption_key_storage', null);
        Config::set('encryption_key_auto_generate', null);
        Config::set_environment('testing');
    }

    public function test_production_refuses_to_generate_key(): void
    {
        Config::set_environment('production');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ENCRYPTION_KEY');

        try {
            KeyManager::get_key();
        } finally {
            $this->assertFileDoesNotExist($this->storage);
        }
    }

    public function test_production_generates_when_explicitly_allowed(): void
    {
        Config::set_environment('production');
        Config::set('encryption_key_auto_generate', true);

        $key = KeyManager::get_key();

        $this->assertSame(32, strlen((string) base64_decode($key, true)));
        $this->assertFileExists($this->storage);
    }

    public function test_development_generates_key_by_default(): void
    {
        Config::set_environment('development');

        $key = KeyManager::get_key();

        $this->assertFileExists($this->storage);
        $this->assertSame($key, KeyManager::get_key());
    }

    public function test_explicit_false_disables_generation_outside_production(): void
    {
        Config::set_environment('development');
        Config::set('encryption_key_auto_generate', 'false');

        $this->assertFalse(KeyManager::auto_generate_allowed());
    }
}
