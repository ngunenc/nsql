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
            'NSQL_MONITORING_ALLOW_QUERY_TOKEN' => getenv('NSQL_MONITORING_ALLOW_QUERY_TOKEN'),
        ];

        foreach (array_keys($this->envBackup) as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
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

    private function set_env(string $key, string $value): void
    {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }

    public function test_query_token_is_ignored_by_default(): void
    {
        $_GET['token'] = 'query-token';
        $this->assertFalse(endpoint_guard::allows_query_token());
        $this->assertNull(endpoint_guard::extract_request_token());
    }

    public function test_query_token_is_accepted_when_opted_in(): void
    {
        $this->set_env('NSQL_MONITORING_ALLOW_QUERY_TOKEN', 'true');
        $_GET['token'] = 'query-token';
        $this->assertSame('query-token', endpoint_guard::extract_request_token());
    }

    public function test_correct_query_token_gets_401_by_default(): void
    {
        $this->set_env('NSQL_MONITORING_TOKEN', 'cfg-token');
        $_GET['token'] = 'cfg-token';

        $denied = endpoint_guard::authorize();

        $this->assertNotNull($denied);
        $this->assertSame(401, $denied['status']);
    }

    public function test_header_token_is_authorized(): void
    {
        $this->set_env('NSQL_MONITORING_TOKEN', 'cfg-token');
        $_SERVER['HTTP_X_NSQL_MONITORING_TOKEN'] = 'cfg-token';
        $this->assertNull(endpoint_guard::authorize());

        unset($_SERVER['HTTP_X_NSQL_MONITORING_TOKEN']);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer cfg-token';
        $this->assertNull(endpoint_guard::authorize());
    }

    public function test_wrong_token_and_missing_config_are_denied(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer whatever';
        $this->assertSame(403, endpoint_guard::authorize()['status']);

        $this->set_env('NSQL_MONITORING_TOKEN', 'cfg-token');
        $this->assertSame(401, endpoint_guard::authorize()['status']);

        $this->set_env('NSQL_MONITORING_ENABLED', 'false');
        $this->assertSame(404, endpoint_guard::authorize()['status']);
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
