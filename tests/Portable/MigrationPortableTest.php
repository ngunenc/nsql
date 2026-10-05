<?php

namespace Tests\Portable;

use nsql\database\migration_manager;
use Tests\Support\PortableTestCase;

class MigrationPortableTest extends PortableTestCase
{
    private string $dir;
    private string $log_table = 'p_migrations';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_pmig_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/migrations', 0777, true);
        mkdir($this->dir . '/seeds', 0777, true);

        foreach ([$this->log_table, 'p_mig_a', 'p_mig_b'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS ' . $table);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (['/migrations', '/seeds', ''] as $sub) {
            @rmdir($this->dir . $sub);
        }

        parent::tearDown();
    }

    private function manager(): migration_manager
    {
        $manager = new migration_manager($this->db, $this->dir . '/migrations', $this->dir . '/seeds');
        $manager->set_migrations_table($this->log_table);

        return $manager;
    }

    private function write_migration(string $file, string $table): void
    {
        file_put_contents($this->dir . '/migrations/' . $file, <<<PHP
<?php

return new class extends \\nsql\\database\\base_migration {
    public function up(): void { \$this->db()->query('CREATE TABLE {$table} (id INT PRIMARY KEY)'); }
    public function down(): void { \$this->db()->query('DROP TABLE IF EXISTS {$table}'); }
};
PHP);
    }

    private function table_exists(string $table): bool
    {
        try {
            $this->db->set_throw_on_error(true);
            $this->db->get_results("SELECT 1 FROM {$table} WHERE 1 = 0");

            return true;
        } catch (\Throwable) {
            return false;
        } finally {
            $this->db->set_throw_on_error(false);
        }
    }

    public function test_migrate_status_and_rollback(): void
    {
        $this->write_migration('2026_01_01_000001_create_a.php', 'p_mig_a');
        $this->write_migration('2026_01_01_000002_create_b.php', 'p_mig_b');

        $this->assertSame(
            ['2026_01_01_000001_create_a.php', '2026_01_01_000002_create_b.php'],
            $this->manager()->migrate()
        );
        $this->assertTrue($this->table_exists('p_mig_a'));
        $this->assertSame('completed', $this->manager()->get_status('2026_01_01_000001_create_a.php')['status'] ?? null);
        $this->assertSame([], $this->manager()->migrate());

        $this->assertCount(2, $this->manager()->rollback());
        $this->assertFalse($this->table_exists('p_mig_a'));
        $this->assertFalse($this->table_exists('p_mig_b'));
        $this->assertSame('rolled_back', $this->manager()->get_status('2026_01_01_000002_create_b.php')['status'] ?? null);
    }
}
