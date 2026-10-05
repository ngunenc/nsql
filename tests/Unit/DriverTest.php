<?php

namespace Tests\Unit;

use nsql\database\drivers\DriverFactory;
use nsql\database\drivers\PgsqlDriver;
use nsql\database\drivers\SqliteDriver;
use PHPUnit\Framework\TestCase;

class DriverTest extends TestCase
{
    public function test_pgsql_dsn_round_trip(): void
    {
        $driver = new PgsqlDriver();
        $dsn = $driver->build_dsn(['host' => 'db', 'port' => 6543, 'dbname' => 'app', 'charset' => 'UTF8']);

        $this->assertSame("pgsql:host=db;port=6543;dbname=app;options='--client_encoding=UTF8'", $dsn);
        $this->assertSame('pgsql', $driver->get_driver_name());
        $this->assertSame('"', $driver->get_identifier_quote());
        $this->assertSame('LIMIT 10', $driver->get_limit_clause(10));
        $this->assertSame('LIMIT 10 OFFSET 20', $driver->get_limit_clause(10, 20));

        $parsed = $driver->parse_dsn("pgsql:host=db;dbname=app;options='--client_encoding=LATIN1'");
        $this->assertSame('db', $parsed['host']);
        $this->assertSame('app', $parsed['dbname']);
        $this->assertSame('LATIN1', $parsed['charset']);
        $this->assertSame(5432, $parsed['port']);

        $this->assertSame(6543, $driver->parse_dsn($dsn)['port']);
        $this->assertSame(['db', 7000], array_values(array_intersect_key($driver->parse_dsn('pgsql:host=db:7000'), ['host' => 1, 'port' => 1])));
    }

    public function test_mysql_dsn_parsing(): void
    {
        $driver = new \nsql\database\drivers\MysqlDriver();

        $this->assertSame(
            ['driver' => 'mysql', 'host' => 'db', 'port' => 3307, 'dbname' => 'app', 'charset' => 'utf8'],
            $driver->parse_dsn('mysql:host=db;port=3307;dbname=app;charset=utf8')
        );
        $this->assertSame(
            ['driver' => 'mysql', 'host' => 'localhost', 'port' => 3306, 'dbname' => 'app', 'charset' => 'utf8mb4'],
            $driver->parse_dsn('mysql:host=localhost;dbname=app')
        );
        $this->assertSame(3308, $driver->parse_dsn('mysql:host=db:3308;dbname=app')['port']);
        $this->assertSame(['db', 3309, 'app'], array_values(array_intersect_key(
            $driver->parse_dsn($driver->build_dsn(['host' => 'db', 'port' => 3309, 'dbname' => 'app'])),
            ['host' => 1, 'port' => 1, 'dbname' => 1]
        )));
    }

    public function test_pgsql_rejects_invalid_dsn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PgsqlDriver())->parse_dsn('mysql:host=x');
    }

    public function test_sqlite_dsn_paths(): void
    {
        $driver = new SqliteDriver();

        $this->assertSame('sqlite::memory:', $driver->build_dsn([]));
        $this->assertSame('sqlite:/tmp/app.sqlite', $driver->build_dsn(['path' => '/tmp/app.sqlite']));
        $this->assertSame('sqlite:C:\\data\\app.sqlite', $driver->build_dsn(['path' => 'C:\\data\\app.sqlite']));
        $this->assertSame('"', $driver->get_identifier_quote());
        $this->assertSame('LIMIT 5 OFFSET 5', $driver->get_limit_clause(5, 5));

        $parsed = $driver->parse_dsn('sqlite:/var/db/app.sqlite');
        $this->assertSame('/var/db/app.sqlite', $parsed['path']);
        $this->assertSame('app.sqlite', $parsed['dbname']);
        $this->assertNull($driver->parse_dsn('sqlite::memory:')['dbname']);
    }

    public function test_sqlite_last_insert_id(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, v TEXT)');
        $pdo->exec("INSERT INTO t (v) VALUES ('a'), ('b')");

        $this->assertSame(2, (new SqliteDriver())->get_last_insert_id($pdo));
    }

    public function test_factory_creates_drivers_by_name(): void
    {
        $this->assertInstanceOf(PgsqlDriver::class, DriverFactory::create('pgsql'));
        $this->assertInstanceOf(SqliteDriver::class, DriverFactory::create('sqlite'));
    }
}
