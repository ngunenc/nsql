<?php

namespace Tests\Integration;

use nsql\database\Config;
use nsql\database\exceptions\ConnectionException;
use nsql\database\exceptions\QueryException;
use nsql\database\Nsql;
use Tests\Support\DatabaseTestCase;

/**
 * #42: debug log'unda hassas parametreler maskeli, interpolasyon tam eşleşmeli,
 * bağlantı hatası mesajında kimlik bilgisi yok.
 */
class DebugLogMaskingTest extends DatabaseTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_debug_' . uniqid('', true);
        Config::set('log_dir', $this->dir);
        Config::set('log_file', 'debug.txt');
    }

    protected function tearDown(): void
    {
        Config::set('log_dir', null);
        Config::set('log_file', null);
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function debug_db(): Nsql
    {
        return new Nsql(
            host: Config::get('db_host', 'localhost'),
            db: Config::get('db_name', 'nsql_test_db'),
            user: Config::get('db_user', 'root'),
            pass: Config::get('db_pass', ''),
            debug: true
        );
    }

    public function test_debug_log_masks_sensitive_params(): void
    {
        $db = $this->debug_db();
        $db->get_row('SELECT :password AS password, :name AS n', ['password' => 'hunter2', 'name' => 'ali']);

        ob_start();
        $db->debug();
        $html = (string) ob_get_clean();

        $log = (string) file_get_contents($this->dir . DIRECTORY_SEPARATOR . 'debug.txt');
        $this->assertStringNotContainsString('hunter2', $log);
        $this->assertStringContainsString('********', $log);
        $this->assertStringContainsString("'ali'", $log);
        $this->assertStringNotContainsString('hunter2', $html);
    }

    public function test_positional_sensitive_params_are_masked_in_exception_and_events(): void
    {
        $db = $this->debug_db();
        $events = [];
        $db->on_query(function ($event) use (&$events) {
            $events[] = $event->params;
        });

        try {
            $db->statement('INSERT INTO test_table (name, password) VALUES (?, ?)', ['ali', 'hunter2']);
            $this->fail('QueryException bekleniyordu');
        } catch (QueryException $e) {
            $this->assertSame(['ali', '********'], $e->get_params());
        }

        $this->assertSame([['ali', '********']], $events);
    }

    public function test_debug_log_records_public_method_name(): void
    {
        $db = $this->debug_db();
        $db->get_results('SELECT 1 AS x');

        ob_start();
        $db->debug();
        ob_end_clean();

        $log = (string) file_get_contents($this->dir . DIRECTORY_SEPARATOR . 'debug.txt');
        $this->assertStringContainsString('Çalıştırılan Metod: get_results', $log);
    }

    public function test_last_called_method_is_not_tracked_outside_debug_mode(): void
    {
        $db = new Nsql(
            host: Config::get('db_host', 'localhost'),
            db: Config::get('db_name', 'nsql_test_db'),
            user: Config::get('db_user', 'root'),
            pass: Config::get('db_pass', ''),
        );
        $db->get_results('SELECT 1 AS x');

        $this->assertSame('unknown', (fn () => $this->last_called_method)->call($db));
    }

    public function test_interpolation_does_not_corrupt_prefixed_placeholders(): void
    {
        $db = $this->debug_db();
        $db->get_row('SELECT :id AS a, :id2 AS b', ['id' => 1, 'id2' => 2]);

        ob_start();
        $db->debug();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString(htmlspecialchars("SELECT '1' AS a, '2' AS b"), $html);
    }

    public function test_connection_error_message_has_no_credentials(): void
    {
        try {
            $db = new Nsql(
                host: Config::get('db_host', 'localhost'),
                db: Config::get('db_name', 'nsql_test_db'),
                user: 'nsql_no_such_user',
                pass: 'wrong-password'
            );
            $db->get_row('SELECT 1');
            $this->fail('ConnectionException bekleniyordu');
        } catch (ConnectionException $e) {
            $this->assertStringNotContainsString('nsql_no_such_user', $e->getMessage());
            $this->assertStringNotContainsString('wrong-password', $e->getMessage());
            $this->assertStringContainsString('1045', $e->getMessage());
        }
    }
}
