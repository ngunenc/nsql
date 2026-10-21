<?php

namespace Tests\Unit;

use nsql\database\Config;
use PHPUnit\Framework\TestCase;

class VendorRootDetectionTest extends TestCase
{
    public function test_detects_vendor_package_paths(): void
    {
        $this->assertTrue(Config::is_vendor_package_path(
            '/var/www/app/vendor/ngunenc/nsql'
        ));
        $this->assertTrue(Config::is_vendor_package_path(
            'C:\\app\\vendor\\ngunenc\\nsql\\storage\\keys'
        ));
        $this->assertFalse(Config::is_vendor_package_path('/var/www/app'));
        $this->assertFalse(Config::is_vendor_package_path('/var/www/app/storage'));
    }

    public function test_resolve_away_from_vendor_returns_app_root(): void
    {
        $this->assertSame(
            '/var/www/app',
            str_replace('\\', '/', Config::resolve_away_from_vendor('/var/www/app/vendor/ngunenc/nsql'))
        );
        $this->assertSame(
            '/var/www/app',
            str_replace('\\', '/', Config::resolve_away_from_vendor('/var/www/app/vendor/ngunenc/nsql/storage/keys'))
        );
    }

    public function test_prefer_application_root_leaves_normal_paths(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_app_root_test';
        $this->assertSame(
            rtrim($path, '/\\'),
            Config::prefer_application_root($path)
        );
    }

    /**
     * `cd /tmp && php /var/www/app/cron.php`: /tmp/.env yüklenmemeli (gerçek ortam değişkenlerini ezer).
     */
    public function test_env_in_world_writable_cwd_is_not_used(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX izinleri gerekli');
        }

        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_root_' . bin2hex(random_bytes(4));
        $shared = $base . DIRECTORY_SEPARATOR . 'shared';
        $app = $base . DIRECTORY_SEPARATOR . 'app';
        mkdir($shared . DIRECTORY_SEPARATOR . 'run', 0755, true);
        mkdir($app, 0755, true);
        chmod($shared, 0777);
        file_put_contents($shared . DIRECTORY_SEPARATOR . '.env', "DB_HOST=attacker.example\n");
        file_put_contents($app . DIRECTORY_SEPARATOR . '.env', "DB_HOST=db.internal\n");
        file_put_contents($app . DIRECTORY_SEPARATOR . 'cron.php', '<?php');

        $cwd = (string) getcwd();
        $script = $_SERVER['SCRIPT_FILENAME'] ?? null;
        $detect = new \ReflectionMethod(Config::class, 'detect_project_root');
        $override = new \ReflectionProperty(Config::class, 'project_root_override');
        $previous_override = $override->getValue();
        $override->setValue(null, null);
        $env_root = getenv('NSQL_PROJECT_ROOT');
        putenv('NSQL_PROJECT_ROOT');

        try {
            chdir($shared . DIRECTORY_SEPARATOR . 'run');

            // Giriş betiğinin uygulaması CWD'den önce gelir
            $_SERVER['SCRIPT_FILENAME'] = $app . DIRECTORY_SEPARATOR . 'cron.php';
            $this->assertSame(realpath($app), realpath((string) $detect->invoke(null)));

            // Betik bilinmese de herkesin yazabildiği dizindeki .env kök sayılmaz
            unset($_SERVER['SCRIPT_FILENAME']);
            $this->assertNotSame(realpath($shared), realpath((string) $detect->invoke(null)));
        } finally {
            chdir($cwd);
            $script !== null ? $_SERVER['SCRIPT_FILENAME'] = $script : null;
            $override->setValue(null, $previous_override);
            $env_root !== false ? putenv('NSQL_PROJECT_ROOT=' . $env_root) : null;
            foreach ([$shared . '/.env', $app . '/.env', $app . '/cron.php'] as $file) {
                @unlink($file);
            }
            @rmdir($shared . '/run');
            @rmdir($shared);
            @rmdir($app);
            @rmdir($base);
        }
    }

    public function test_set_project_root_escapes_vendor_package_path(): void
    {
        $app = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_vendor_escape_' . uniqid('', true);
        $pkg = $app . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'ngunenc' . DIRECTORY_SEPARATOR . 'nsql';
        mkdir($pkg, 0700, true);
        file_put_contents($app . DIRECTORY_SEPARATOR . '.env', "DB_HOST=localhost\n");

        Config::set_project_root($pkg);
        Config::refresh();

        $root = str_replace('\\', '/', Config::get_project_root());
        // Windows'ta sys_get_temp_dir() 8.3 kısa adı (RUNNER~1) döndürebilir
        $expected = str_replace('\\', '/', (string) realpath($app));
        $root = str_replace('\\', '/', (string) (realpath($root) ?: $root));
        $this->assertSame($expected, $root);

        Config::set_project_root(dirname(__DIR__, 2));
        Config::refresh();

        // cleanup
        @unlink($app . DIRECTORY_SEPARATOR . '.env');
        @rmdir($pkg);
        @rmdir(dirname($pkg));
        @rmdir(dirname($pkg, 2));
        @rmdir($app);
    }
}
