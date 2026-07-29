<?php

namespace Tests\Integration;

use nsql\database\migration;
use nsql\database\migrations\create_test_table;
use nsql\database\migrations\create_users_table;
use Tests\Support\DatabaseTestCase;

class MigrationSmokeTest extends DatabaseTestCase
{
    public function test_builtin_migrations_implement_interface(): void
    {
        $users = new create_users_table();
        $test = new create_test_table();

        $this->assertInstanceOf(migration::class, $users);
        $this->assertInstanceOf(migration::class, $test);
        $this->assertNotSame('', $users->get_description());
        $this->assertNotSame('', $test->get_description());
        $this->assertIsArray($users->get_dependencies());
        $this->assertIsArray($test->get_dependencies());
    }

    public function test_test_table_exists_after_migration(): void
    {
        $rows = $this->db->get_results("SHOW TABLES LIKE 'test_table'");
        $this->assertNotEmpty($rows);
    }
}
