<?php

namespace Tests\Unit;

use nsql\database\monitoring\endpoint_guard;
use PHPUnit\Framework\TestCase;

class EndpointGuardTest extends TestCase
{
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->envBackup = [
            'NSQL_MONITORING_TOKEN' => getenv('NSQL_MONITORING_TOKEN'),
            'NSQL_MONITORING_ENABLED' => getenv('NSQL_MONITORING_ENABLED'),
        ];

        putenv('NSQL_MONITORING_TOKEN');
        putenv('NSQL_MONITORING_ENABLED');
        unset($_ENV['NSQL_MONITORING_TOKEN'], $_SERVER['NSQL_MONITORING_TOKEN']);
        unset($_ENV['NSQL_MONITORING_ENABLED'], $_SERVER['NSQL_MONITORING_ENABLED']);
        unset($_GET['token'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_NSQL_MONITORING_TOKEN']);
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if ($value === false || $value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }

        unset($_GET['token'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_NSQL_MONITORING_TOKEN']);
    }

    public function test_disabled_when_enabled_false(): void
    {
        putenv('NSQL_MONITORING_ENABLED=false');
        $_ENV['NSQL_MONITORING_ENABLED'] = 'false';

        $this->assertFalse(endpoint_guard::is_enabled());
    }

    public function test_enabled_by_default(): void
    {
        $this->assertTrue(endpoint_guard::is_enabled());
    }

    public function test_extract_bearer_token(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret-token-123';
        $this->assertSame('secret-token-123', endpoint_guard::extract_request_token());
    }

    public function test_extract_custom_header_token(): void
    {
        $_SERVER['HTTP_X_NSQL_MONITORING_TOKEN'] = 'header-token';
        $this->assertSame('header-token', endpoint_guard::extract_request_token());
    }

    public function test_extract_query_token(): void
    {
        $_GET['token'] = 'query-token';
        $this->assertSame('query-token', endpoint_guard::extract_request_token());
    }

    public function test_configured_token_from_env(): void
    {
        putenv('NSQL_MONITORING_TOKEN=cfg-token');
        $_ENV['NSQL_MONITORING_TOKEN'] = 'cfg-token';
        $this->assertSame('cfg-token', endpoint_guard::get_configured_token());
    }

    public function test_missing_configured_token_is_null(): void
    {
        $this->assertNull(endpoint_guard::get_configured_token());
    }
}
