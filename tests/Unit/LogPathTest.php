<?php

namespace Tests\Unit;

use nsql\database\Config;
use nsql\security\SecurityManager;
use PHPUnit\Framework\TestCase;

class LogPathTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        Config::set_project_root($this->root);
        Config::refresh();
    }

    protected function tearDown(): void
    {
        Config::set('log_dir', null);
    }

    private function norm(string $p): string
    {
        return str_replace('\\', '/', $p);
    }

    public function test_relative_file_goes_under_project_storage_logs(): void
    {
        Config::set('log_dir', null);
        $this->assertSame(
            $this->norm($this->root) . '/storage/logs/error_log.txt',
            $this->norm(Config::resolve_log_path('error_log.txt'))
        );
    }

    public function test_absolute_paths_are_kept(): void
    {
        $this->assertSame('/var/log/nsql.log', Config::resolve_log_path('/var/log/nsql.log'));
        $this->assertSame('C:\\logs\\nsql.log', Config::resolve_log_path('C:\\logs\\nsql.log'));
        $this->assertSame('C:/logs/nsql.log', Config::resolve_log_path('C:/logs/nsql.log'));
    }

    public function test_relative_log_dir_is_anchored_to_project_root(): void
    {
        Config::set('log_dir', 'var/log');
        $this->assertSame(
            $this->norm($this->root) . '/var/log/app.log',
            $this->norm(Config::resolve_log_path('app.log'))
        );
    }

    public function test_security_manager_debug_log_does_not_write_to_cwd(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_logpath_' . uniqid('', true);
        Config::set('log_dir', $dir);
        $name = 'sm_debug_' . uniqid() . '.txt';

        SecurityManager::log_debug_info('hello', [], $name);

        $this->assertFileExists($dir . DIRECTORY_SEPARATOR . $name);
        $this->assertFileDoesNotExist(getcwd() . DIRECTORY_SEPARATOR . $name);

        @unlink($dir . DIRECTORY_SEPARATOR . $name);
        @rmdir($dir);
    }
}
