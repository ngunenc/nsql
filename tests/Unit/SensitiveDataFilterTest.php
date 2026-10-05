<?php

namespace Tests\Unit;

use nsql\database\config;
use nsql\database\connection_pool;
use nsql\security\security_manager;
use nsql\database\security\sensitive_data_filter;
use PHPUnit\Framework\TestCase;

class SensitiveDataFilterTest extends TestCase
{
    protected function setUp(): void
    {
        config::set_project_root(dirname(__DIR__, 2));
        config::refresh();
    }

    protected function tearDown(): void
    {
        config::set('sensitive_keys', null);
        config::set('log_dir', null);
    }

    public function test_default_keys_are_masked_including_named_placeholders(): void
    {
        $masked = sensitive_data_filter::mask_array([
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
        $this->assertSame(['a', 'b'], sensitive_data_filter::mask_array(['a', 'b']));
    }

    public function test_sensitive_keys_config_extends_list(): void
    {
        config::set('sensitive_keys', 'phone, iban_no');

        $masked = sensitive_data_filter::mask_array(['phone' => '555', 'iban_no' => 'TR', 'city' => 'x']);

        $this->assertSame('********', $masked['phone']);
        $this->assertSame('********', $masked['iban_no']);
        $this->assertSame('x', $masked['city']);
    }

    public function test_filter_does_not_mutate_original_object(): void
    {
        $obj = (object) ['password' => 'secret', 'name' => 'a'];

        $filtered = (new sensitive_data_filter())->filter($obj);

        $this->assertSame('********', $filtered->password);
        $this->assertSame('secret', $obj->password);
    }

    public function test_security_manager_debug_log_is_masked(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_mask_' . uniqid('', true);
        config::set('log_dir', $dir);

        security_manager::log_debug_info('login', ['user' => 'ali', 'password' => 'hunter2', 'token' => 'abc'], 'mask.txt');

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

        $message = connection_pool::safe_error_message($e);

        $this->assertStringNotContainsString('root', $message);
        $this->assertStringNotContainsString('db.internal', $message);
        $this->assertStringContainsString('1045', $message);
        $this->assertStringContainsString('HY000', $message);
    }
}
