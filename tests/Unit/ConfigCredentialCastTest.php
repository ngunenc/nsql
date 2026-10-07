<?php

namespace Tests\Unit;

use nsql\database\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfigCredentialCastTest extends TestCase
{
    private const ENV_KEYS = ['NSQL_T_PASS', 'NSQL_T_PORT'];

    private string $tempRoot;

    protected function setUp(): void
    {
        $this->tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_cred_test_' . uniqid('', true);
        mkdir($this->tempRoot, 0700, true);
        Config::set_project_root($this->tempRoot);
        Config::refresh();
    }

    protected function tearDown(): void
    {
        foreach (self::ENV_KEYS as $key) {
            putenv($key);
        }
        Config::set_project_root(dirname(__DIR__, 2));
        Config::refresh();

        $env_file = $this->tempRoot . DIRECTORY_SEPARATOR . '.env';
        if (is_file($env_file)) {
            unlink($env_file);
        }
        @rmdir($this->tempRoot);
    }

    private function write_env(string $contents): void
    {
        file_put_contents($this->tempRoot . DIRECTORY_SEPARATOR . '.env', $contents);
        Config::refresh();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function credential_values(): array
    {
        return [
            'leading zero' => ['0123'],
            'exponent' => ['1e3'],
            'boolean word' => ['yes'],
            'null word' => ['null'],
            'json like' => ['[1]'],
        ];
    }

    #[DataProvider('credential_values')]
    public function test_dotenv_credentials_stay_strings(string $value): void
    {
        $this->write_env("DB_PASS={$value}\nDB_USER={$value}\nDB_NAME={$value}\nREDIS_PASSWORD={$value}\nENCRYPTION_KEY={$value}\n");

        foreach (['db_pass', 'db_user', 'db_name', 'redis_password', 'encryption_key'] as $key) {
            $this->assertSame($value, Config::get($key), $key);
        }
    }

    #[DataProvider('credential_values')]
    public function test_environment_credentials_stay_strings(string $value): void
    {
        putenv('NSQL_T_PASS=' . $value);

        $this->assertSame($value, Config::get('nsql_t_pass'));
    }

    public function test_non_credential_values_are_still_cast(): void
    {
        $this->write_env("DB_PORT=3306\nDEBUG_MODE=true\nQUERY_CACHE_TIMEOUT=60\n");
        putenv('NSQL_T_PORT=5432');

        $this->assertSame(3306, Config::get('db_port'));
        $this->assertTrue(Config::get('debug_mode'));
        $this->assertSame(60, Config::get('query_cache_timeout'));
        $this->assertSame(5432, Config::get('nsql_t_port'));
    }

    public function test_quoted_values_are_not_cast(): void
    {
        $this->write_env("QUERY_CACHE_PREFIX=\"123\"\nLOG_FILE='true'\nEMPTY_QUOTED=\"\"\n");

        $this->assertSame('123', Config::get('query_cache_prefix'));
        $this->assertSame('true', Config::get('log_file'));
        $this->assertSame('', Config::get('empty_quoted'));
    }
}
