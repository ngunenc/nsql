<?php

namespace Tests\Integration;

use nsql\database\Config;
use nsql\database\exceptions\DatabaseException;
use nsql\database\Nsql;
use nsql\database\orm\ModelNotFoundException;
use PHPUnit\Framework\TestCase;

class DatabaseExceptionDetailsTest extends TestCase
{
    public function test_base_exception_has_details(): void
    {
        $e = new DatabaseException('x', 7, null, ['k' => 'v'], 'SELECT 1', [1]);

        $details = $e->get_details();
        $this->assertSame('x', $details['message']);
        $this->assertSame(7, $details['code']);
        $this->assertSame('SELECT 1', $details['query']);
        $this->assertSame([1], $details['params']);
        $this->assertSame(['k' => 'v'], $details['context']);
    }

    public function test_safe_execute_handles_base_and_subclass_without_fatal(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite gerekli');
        }
        Config::set_environment('testing');
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_exc_' . getmypid() . '.sqlite';
        $db = new Nsql(db: $file, driver: 'sqlite');

        try {
            $run = fn (\Throwable $e) => (fn () => $this->safe_execute_operation(
                static fn () => throw $e,
                'default'
            ))->call($db);

            $this->assertSame('default', $run(new DatabaseException('base')));
            $this->assertSame('default', $run(new ModelNotFoundException('User', 5)));
        } finally {
            (fn () => $this->disconnect())->call($db);
            @unlink($file);
        }
    }
}
