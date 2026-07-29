<?php

namespace Tests\Unit;

use nsql\database\config;
use PHPUnit\Framework\TestCase;

class VendorRootDetectionTest extends TestCase
{
    public function test_detects_vendor_package_paths(): void
    {
        $this->assertTrue(config::is_vendor_package_path(
            '/var/www/app/vendor/ngunenc/nsql'
        ));
        $this->assertTrue(config::is_vendor_package_path(
            'C:\\app\\vendor\\ngunenc\\nsql\\storage\\keys'
        ));
        $this->assertFalse(config::is_vendor_package_path('/var/www/app'));
        $this->assertFalse(config::is_vendor_package_path('/var/www/app/storage'));
    }

    public function test_resolve_away_from_vendor_returns_app_root(): void
    {
        $this->assertSame(
            '/var/www/app',
            str_replace('\\', '/', config::resolve_away_from_vendor('/var/www/app/vendor/ngunenc/nsql'))
        );
        $this->assertSame(
            '/var/www/app',
            str_replace('\\', '/', config::resolve_away_from_vendor('/var/www/app/vendor/ngunenc/nsql/storage/keys'))
        );
    }

    public function test_prefer_application_root_leaves_normal_paths(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_app_root_test';
        $this->assertSame(
            rtrim($path, '/\\'),
            config::prefer_application_root($path)
        );
    }

    public function test_set_project_root_escapes_vendor_package_path(): void
    {
        $app = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_vendor_escape_' . uniqid('', true);
        $pkg = $app . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'ngunenc' . DIRECTORY_SEPARATOR . 'nsql';
        mkdir($pkg, 0700, true);
        file_put_contents($app . DIRECTORY_SEPARATOR . '.env', "DB_HOST=localhost\n");

        config::set_project_root($pkg);
        config::refresh();

        $root = str_replace('\\', '/', config::get_project_root());
        $expected = str_replace('\\', '/', $app);
        $this->assertSame($expected, $root);

        config::set_project_root(dirname(__DIR__, 2));
        config::refresh();

        // cleanup
        @unlink($app . DIRECTORY_SEPARATOR . '.env');
        @rmdir($pkg);
        @rmdir(dirname($pkg));
        @rmdir(dirname($pkg, 2));
        @rmdir($app);
    }
}
