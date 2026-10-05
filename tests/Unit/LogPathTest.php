<?php

namespace Tests\Unit;

use nsql\database\config;
use nsql\database\security\security_manager;
use PHPUnit\Framework\TestCase;

class LogPathTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        config::set_project_root($this->root);
        config::refresh();
    }

    protected function tearDown(): void
    {
        config::set('log_dir', null);
    }

    private function norm(string $p): string
    {
        return str_replace('\\', '/', $p);
    }

    public function test_relative_file_goes_under_project_storage_logs(): void
    {
        config::set('log_dir', null);
        $this->assertSame(
            $this->norm($this->root) . '/storage/logs/error_log.txt',
            $this->norm(config::resolve_log_path('error_log.txt'))
        );
    }

    public function test_absolute_paths_are_kept(): void
    {
        $this->assertSame('/var/log/nsql.log', config::resolve_log_path('/var/log/nsql.log'));
        $this->assertSame('C:\\logs\\nsql.log', config::resolve_log_path('C:\\logs\\nsql.log'));
        $this->assertSame('C:/logs/nsql.log', config::resolve_log_path('C:/logs/nsql.log'));
    }

    public function test_relative_log_dir_is_anchored_to_project_root(): void
    {
        config::set('log_dir', 'var/log');
        $this->assertSame(
            $this->norm($this->root) . '/var/log/app.log',
            $this->norm(config::resolve_log_path('app.log'))
        );
    }

    public function test_security_manager_debug_log_does_not_write_to_cwd(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_logpath_' . uniqid('', true);
        config::set('log_dir', $dir);
        $name = 'sm_debug_' . uniqid() . '.txt';

        security_manager::log_debug_info('hello', [], $name);

        $this->assertFileExists($dir . DIRECTORY_SEPARATOR . $name);
        $this->assertFileDoesNotExist(getcwd() . DIRECTORY_SEPARATOR . $name);

        @unlink($dir . DIRECTORY_SEPARATOR . $name);
        @rmdir($dir);
    }
}
