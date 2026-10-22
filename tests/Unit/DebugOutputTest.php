<?php

namespace Tests\Unit;

use nsql\database\Config;
use nsql\database\Nsql;
use PHPUnit\Framework\TestCase;

class DebugOutputTest extends TestCase
{
    /**
     * Debug kapalıyken debug() HTML basıyordu; production yanıtına (JSON vb.) karışıyordu.
     */
    public function test_debug_prints_nothing_when_debug_mode_is_off(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite gerekli');
        }
        Config::set_environment('testing');
        Config::set_project_root(dirname(__DIR__, 2));
        $db = new Nsql(db: ':memory:', driver: 'sqlite', debug: false);

        $notices = [];
        set_error_handler(static function (int $level, string $message) use (&$notices): bool {
            $notices[] = [$level, $message];

            return true;
        });
        ob_start();
        try {
            $db->debug();
        } finally {
            $output = (string) ob_get_clean();
            restore_error_handler();
        }

        $this->assertSame('', $output);
        $this->assertCount(1, $notices);
        $this->assertSame(E_USER_NOTICE, $notices[0][0]);
    }
}
