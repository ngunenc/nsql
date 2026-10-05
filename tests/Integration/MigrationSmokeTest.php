<?php

namespace Tests\Integration;

use nsql\database\Migration;
use Tests\Fixtures\Migrations\create_test_table;
use Tests\Fixtures\Migrations\create_users_table;
use Tests\Support\DatabaseTestCase;

class MigrationSmokeTest extends DatabaseTestCase
{
    public function test_fixture_migrations_implement_interface(): void
    {
        $users = new create_users_table();
        $test = new create_test_table();

        $this->assertInstanceOf(Migration::class, $users);
        $this->assertInstanceOf(Migration::class, $test);
        $this->assertNotSame('', $users->get_description());
        $this->assertNotSame('', $test->get_description());
        $this->assertSame([], $users->get_dependencies());
        $this->assertSame([], $test->get_dependencies());
    }

    public function test_test_table_exists_after_migration(): void
    {
        $rows = $this->db->get_results("SHOW TABLES LIKE 'test_table'");
        $this->assertNotEmpty($rows);
    }
}
