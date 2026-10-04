<?php

namespace Tests\Integration;

use nsql\database\config;
use nsql\database\migration_manager;
use Tests\Support\DatabaseTestCase;

class MigrationManagerTest extends DatabaseTestCase
{
    private string $dir;
    private string $suffix;
    private string $log_table;
    /** @var array<string> */
    private array $tables = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->suffix = bin2hex(random_bytes(4));
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_mig_' . $this->suffix;
        mkdir($this->dir . '/migrations', 0777, true);
        mkdir($this->dir . '/seeds', 0777, true);
        $this->log_table = 'nsql_mig_log_' . $this->suffix;
        $this->tables[] = $this->log_table;
    }

    protected function tearDown(): void
    {
        foreach ($this->tables as $table) {
            $this->db->query("DROP TABLE IF EXISTS {$table}");
        }
        $this->remove_dir($this->dir);
        config::set('MIGRATIONS_PATH', 'database/migrations');
        parent::tearDown();
    }

    private function manager(): migration_manager
    {
        $manager = new migration_manager($this->db, $this->dir . '/migrations', $this->dir . '/seeds');
        $manager->set_migrations_table($this->log_table);

        return $manager;
    }

    private function table_exists(string $table): bool
    {
        return $this->db->get_results('SHOW TABLES LIKE ' . $this->db->get_pdo()->quote($table)) !== [];
    }

    private function write_table_migration(string $file, string $table, array $deps = [], bool $fail = false): void
    {
        $this->tables[] = $table;
        $deps_code = var_export($deps, true);
        $up = $fail
            ? "throw new \\RuntimeException('kasıtlı hata');"
            : "\$this->db()->query('CREATE TABLE {$table} (id INT PRIMARY KEY)');";
        file_put_contents($this->dir . '/migrations/' . $file, <<<PHP
<?php

return new class extends \\nsql\\database\\base_migration {
    public function up(): void { {$up} }
    public function down(): void { \$this->db()->query('DROP TABLE IF EXISTS {$table}'); }
    public function get_dependencies(): array { return {$deps_code}; }
};
PHP);
    }

    private function remove_dir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->remove_dir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function test_constructor_does_not_touch_database(): void
    {
        $this->manager();

        $this->assertFalse($this->table_exists($this->log_table));
    }

    public function test_default_paths_resolve_under_project_root(): void
    {
        $manager = new migration_manager($this->db);
        $root = rtrim(config::get_project_root(), '/\\');

        $this->assertSame($root . DIRECTORY_SEPARATOR . 'database/migrations', $manager->get_migrations_path());
        $this->assertSame($root . DIRECTORY_SEPARATOR . 'database/seeds', $manager->get_seeds_path());

        config::set('MIGRATIONS_PATH', 'db/schema');
        $this->assertSame($root . DIRECTORY_SEPARATOR . 'db/schema', (new migration_manager($this->db))->get_migrations_path());
    }

    public function test_created_template_is_loadable_and_receives_connection(): void
    {
        $manager = $this->manager();
        $path = $manager->create('add_something');

        $this->assertMatchesRegularExpression('/\d{4}_\d{2}_\d{2}_\d{6}_add_something\.php$/', $path);

        $executed = $manager->migrate();
        $this->assertSame([basename($path)], $executed);
        $this->assertSame('completed', $manager->get_status(basename($path))['status']);
    }

    public function test_migrate_and_rollback_with_anonymous_class_migrations(): void
    {
        $a = 'nsql_mig_a_' . $this->suffix;
        $b = 'nsql_mig_b_' . $this->suffix;
        $this->write_table_migration('2026_01_01_000001_create_a.php', $a);
        $this->write_table_migration('2026_01_01_000002_create_b.php', $b, ['2026_01_01_000001_create_a.php']);

        $manager = $this->manager();
        $this->assertSame(['2026_01_01_000001_create_a.php', '2026_01_01_000002_create_b.php'], $manager->migrate());
        $this->assertTrue($this->table_exists($a));
        $this->assertTrue($this->table_exists($b));

        $this->assertSame([], $this->manager()->migrate());

        $rolled_back = $this->manager()->rollback();
        $this->assertSame(['2026_01_01_000002_create_b.php', '2026_01_01_000001_create_a.php'], $rolled_back);
        $this->assertFalse($this->table_exists($a));
        $this->assertFalse($this->table_exists($b));
    }

    public function test_class_based_migration_with_date_prefix_and_namespace(): void
    {
        $table = 'nsql_mig_cls_' . $this->suffix;
        $this->tables[] = $table;
        $namespace = 'MigTest' . $this->suffix;
        file_put_contents($this->dir . '/migrations/2026_02_02_000001_create_cls_table.php', <<<PHP
<?php

namespace {$namespace};

class create_cls_table extends \\nsql\\database\\base_migration
{
    public function up(): void { \$this->db()->query('CREATE TABLE {$table} (id INT)'); }
    public function down(): void { \$this->db()->query('DROP TABLE IF EXISTS {$table}'); }
}
PHP);

        $this->assertSame(['2026_02_02_000001_create_cls_table.php'], $this->manager()->migrate());
        $this->assertTrue($this->table_exists($table));
    }

    public function test_failed_migration_is_logged(): void
    {
        $this->write_table_migration('2026_03_03_000001_broken.php', 'nsql_mig_never_' . $this->suffix, [], true);
        $manager = $this->manager();

        try {
            $manager->migrate();
            $this->fail('Hata bekleniyordu');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('kasıtlı hata', $e->getMessage());
        }

        $status = $manager->get_status('2026_03_03_000001_broken.php');
        $this->assertSame('failed', $status['status']);
        $this->assertStringContainsString('kasıtlı hata', (string) $status['error_message']);
        $this->assertNotNull($status['duration']);
    }

    public function test_dry_run_does_not_execute_or_log(): void
    {
        $table = 'nsql_mig_dry_' . $this->suffix;
        $this->write_table_migration('2026_04_04_000001_dry.php', $table);
        $manager = $this->manager();
        $manager->set_dry_run(true);

        $this->assertSame(['2026_04_04_000001_dry.php'], $manager->migrate());
        $this->assertFalse($this->table_exists($table));
        $this->assertNull($manager->get_status('2026_04_04_000001_dry.php'));
    }

    public function test_legacy_migrations_table_is_upgraded(): void
    {
        $this->db->query("CREATE TABLE {$this->log_table} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration_name VARCHAR(255) NOT NULL,
            batch INT NOT NULL,
            status ENUM('pending', 'completed', 'failed') DEFAULT 'pending',
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $this->manager()->get_all_statuses();

        $columns = [];
        foreach ($this->db->get_results("SHOW COLUMNS FROM {$this->log_table}") as $column) {
            $columns[$column->Field] = strtolower($column->Type);
        }
        foreach (['error_message', 'duration', 'rolled_back_at', 'rolled_back_by', 'rollback_batch'] as $name) {
            $this->assertArrayHasKey($name, $columns);
        }
        $this->assertStringContainsString('rolled_back', $columns['status']);
    }

    public function test_seed_runs_anonymous_seeder_with_connection(): void
    {
        $manager = $this->manager();
        $path = $manager->create_seeder('demo_seeder');
        file_put_contents($path, <<<'PHP'
<?php

use nsql\database\nsql;

return new class {
    public function run(nsql $db): void
    {
        $db->insert('INSERT INTO test_table (name) VALUES (:name)', ['name' => 'seeded']);
    }
};
PHP);

        $manager->seed('demo_seeder');

        $this->assertSame('seeded', $this->db->get_row('SELECT name FROM test_table')->name);
    }

    public function test_invalid_names_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->manager()->create('../evil');
    }

    public function test_invalid_table_name_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->manager()->set_migrations_table('migrations; DROP TABLE users');
    }
}
