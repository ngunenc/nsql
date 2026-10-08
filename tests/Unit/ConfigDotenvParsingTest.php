<?php

namespace Tests\Unit;

use nsql\database\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * .env ayrıştırma: satır sonu yorumu, export öneki, tırnaklar ve ENV_OVERRIDES_DOTENV (#93).
 */
class ConfigDotenvParsingTest extends TestCase
{
    private const ENV_KEYS = ['NSQL_T_HOST', 'ENV_OVERRIDES_DOTENV'];

    private string $tempRoot;

    protected function setUp(): void
    {
        $this->tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_dotenv_test_' . uniqid('', true);
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
     * @return array<string, array{0: string, 1: array{0: string, 1: string, 2: bool}|null}>
     */
    public static function lines(): array
    {
        return [
            'düz' => ['DB_HOST=localhost', ['DB_HOST', 'localhost', false]],
            'satır sonu yorumu' => ['DB_HOST=localhost # yerel', ['DB_HOST', 'localhost', false]],
            'sekme + yorum' => ["DB_PORT=3306\t# varsayılan", ['DB_PORT', '3306', false]],
            'boşluksuz # değerin parçası' => ['DB_PASS=ab#cd', ['DB_PASS', 'ab#cd', false]],
            'yalnızca yorum' => ['DB_PASS= # boş', ['DB_PASS', '', false]],
            'export öneki' => ['export DB_NAME=app', ['DB_NAME', 'app', false]],
            'export + tırnak + yorum' => ['export DB_PASS="a # b" # not', ['DB_PASS', 'a # b', true]],
            'tek tırnak' => ["DB_PASS='0123'", ['DB_PASS', '0123', true]],
            'boşluklu anahtar' => ['  db_user =  root  ', ['DB_USER', 'root', false]],
            'kapanmayan tırnak' => ['DB_PASS="abc', ['DB_PASS', '"abc', false]],
            'eşittirli değer' => ['DSN=mysql:host=a;port=1', ['DSN', 'mysql:host=a;port=1', false]],
            'yorum satırı' => ['# DB_HOST=x', null],
            'boş satır' => ['   ', null],
            'eşittirsiz' => ['export', null],
        ];
    }

    #[DataProvider('lines')]
    public function test_parse_env_line(string $line, ?array $expected): void
    {
        $this->assertSame($expected, Config::parse_env_line($line));
    }

    public function test_file_values_are_cast_and_quoted_values_stay_strings(): void
    {
        $this->write_env("export NSQL_T_PORT=5432 # port\nNSQL_T_FLAG=true # açık\nNSQL_T_CODE=\"0123\" # metin\n");

        $this->assertSame(5432, Config::get('NSQL_T_PORT'));
        $this->assertTrue(Config::get('NSQL_T_FLAG'));
        $this->assertSame('0123', Config::get('NSQL_T_CODE'));
    }

    public function test_dotenv_wins_by_default(): void
    {
        putenv('NSQL_T_HOST=from-env');
        $this->write_env("NSQL_T_HOST=from-dotenv\n");

        $this->assertSame('from-dotenv', Config::get('NSQL_T_HOST'));
    }

    public function test_environment_wins_when_enabled(): void
    {
        putenv('ENV_OVERRIDES_DOTENV=true');
        putenv('NSQL_T_HOST=from-env');
        $this->write_env("NSQL_T_HOST=from-dotenv\nNSQL_T_ONLY_FILE=file\n");

        $this->assertSame('from-env', Config::get('NSQL_T_HOST'));
        $this->assertSame('file', Config::get('NSQL_T_ONLY_FILE'));
    }
}
