<?php

namespace Tests\Unit;

use nsql\database\Config;
use nsql\database\ConnectionPool;
use nsql\security\SecurityManager;
use nsql\database\security\SensitiveDataFilter;
use PHPUnit\Framework\TestCase;

class SensitiveDataFilterTest extends TestCase
{
    protected function setUp(): void
    {
        Config::set_project_root(dirname(__DIR__, 2));
        Config::refresh();
    }

    protected function tearDown(): void
    {
        Config::set('sensitive_keys', null);
        Config::set('log_dir', null);
    }

    public function test_default_keys_are_masked_including_named_placeholders(): void
    {
        $masked = SensitiveDataFilter::mask_array([
            'password' => 'p@ss',
            ':user_password' => 'x',
            'api_token' => 't',
            'client_secret' => 's',
            'id' => 5,
            'name' => 'ali',
            'nested' => ['passwd' => 'n', 'ok' => 1],
        ]);

        $this->assertSame('********', $masked['password']);
        $this->assertSame('********', $masked[':user_password']);
        $this->assertSame('********', $masked['api_token']);
        $this->assertSame('********', $masked['client_secret']);
        $this->assertSame('********', $masked['nested']['passwd']);
        $this->assertSame(5, $masked['id']);
        $this->assertSame('ali', $masked['name']);
        $this->assertSame(1, $masked['nested']['ok']);
    }

    public function test_positional_params_are_not_treated_as_keys(): void
    {
        $this->assertSame(['a', 'b'], SensitiveDataFilter::mask_array(['a', 'b']));
    }

    public function test_positional_insert_values_are_masked_by_column(): void
    {
        $masked = SensitiveDataFilter::mask_params(
            'INSERT INTO `users` (`email`, password, created_at) VALUES (?, ?, NOW())',
            ['a@b.c', 'hash']
        );

        $this->assertSame(['a@b.c', '********'], $masked);
    }

    public function test_multi_row_insert_maps_each_tuple(): void
    {
        $masked = SensitiveDataFilter::mask_params(
            "INSERT INTO users (name, api_token, note) VALUES (?, ?, COALESCE(?, 'x')), (?, ?, ?)",
            ['a', 't1', 'n1', 'b', 't2', 'n2']
        );

        $this->assertSame(['a', '********', 'n1', 'b', '********', 'n2'], $masked);
    }

    public function test_positional_update_and_where_are_masked_by_column(): void
    {
        $masked = SensitiveDataFilter::mask_params(
            "UPDATE users SET name = ?, u.password = ? WHERE note = '?' AND id = ? AND secret_key IN (?, ?)",
            ['ali', 'hash', 7, 's1', 's2']
        );

        $this->assertSame(['ali', '********', 7, '********', '********'], $masked);
    }

    public function test_named_placeholders_are_masked_by_column(): void
    {
        $masked = SensitiveDataFilter::mask_params(
            'INSERT INTO users (email, password) VALUES (:__p0, :__p1) ON DUPLICATE KEY UPDATE password = :__p2',
            [
                ':__p0' => ['value' => 'a@b.c', 'type' => \PDO::PARAM_STR],
                ':__p1' => ['value' => 'h1', 'type' => \PDO::PARAM_STR],
                ':__p2' => ['value' => 'h2', 'type' => \PDO::PARAM_STR],
            ]
        );

        $this->assertSame('a@b.c', $masked[':__p0']['value']);
        $this->assertSame('********', $masked[':__p1']['value']);
        $this->assertSame(\PDO::PARAM_STR, $masked[':__p1']['type']);
        $this->assertSame('********', $masked[':__p2']['value']);

        $this->assertSame(
            ['id' => 3, 'pw' => '********'],
            SensitiveDataFilter::mask_params('UPDATE users SET password = :pw WHERE id = :id', ['id' => 3, 'pw' => 'x'])
        );
    }

    public function test_unmapped_values_are_kept(): void
    {
        $this->assertSame(
            [1, 'x'],
            SensitiveDataFilter::mask_params('SELECT * FROM t WHERE a BETWEEN ? AND ?', [1, 'x'])
        );
        $this->assertSame([5], SensitiveDataFilter::mask_params('SELECT ?::int', [5]));
    }

    public function test_sensitive_keys_config_extends_list(): void
    {
        Config::set('sensitive_keys', 'phone, iban_no');

        $masked = SensitiveDataFilter::mask_array(['phone' => '555', 'iban_no' => 'TR', 'city' => 'x']);

        $this->assertSame('********', $masked['phone']);
        $this->assertSame('********', $masked['iban_no']);
        $this->assertSame('x', $masked['city']);
    }

    public function test_filter_does_not_mutate_original_object(): void
    {
        $obj = (object) ['password' => 'secret', 'name' => 'a'];

        $filtered = (new SensitiveDataFilter())->filter($obj);

        $this->assertSame('********', $filtered->password);
        $this->assertSame('secret', $obj->password);
    }

    public function test_security_manager_debug_log_is_masked(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_mask_' . uniqid('', true);
        Config::set('log_dir', $dir);

        SecurityManager::log_debug_info('login', ['user' => 'ali', 'password' => 'hunter2', 'token' => 'abc'], 'mask.txt');

        $content = (string) file_get_contents($dir . DIRECTORY_SEPARATOR . 'mask.txt');
        $this->assertStringNotContainsString('hunter2', $content);
        $this->assertStringNotContainsString('"abc"', $content);
        $this->assertStringContainsString('ali', $content);

        @unlink($dir . DIRECTORY_SEPARATOR . 'mask.txt');
        @rmdir($dir);
    }

    public function test_safe_connection_message_has_no_credentials(): void
    {
        $e = new \PDOException("SQLSTATE[HY000] [1045] Access denied for user 'root'@'db.internal' (using password: YES)");

        $message = ConnectionPool::safe_error_message($e);

        $this->assertStringNotContainsString('root', $message);
        $this->assertStringNotContainsString('db.internal', $message);
        $this->assertStringContainsString('1045', $message);
        $this->assertStringContainsString('HY000', $message);
    }
}
