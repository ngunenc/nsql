<?php

namespace Tests\Portable;

use nsql\database\Config;
use nsql\database\exceptions\MigrationException;
use nsql\database\MigrationManager;
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

        foreach ([$this->log_table, 'p_mig_a', 'p_mig_b', 'p_mig_c'] as $table) {
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

    private function manager(): MigrationManager
    {
        $manager = new MigrationManager($this->db, $this->dir . '/migrations', $this->dir . '/seeds');
        $manager->set_migrations_table($this->log_table);

        return $manager;
    }

    /**
     * @param list<string> $depends_on
     */
    private function write_migration(string $file, string $table, array $depends_on = []): void
    {
        $deps = var_export($depends_on, true);
        file_put_contents($this->dir . '/migrations/' . $file, <<<PHP
<?php

return new class extends \\nsql\\database\\BaseMigration {
    public function get_dependencies(): array { return {$deps}; }
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

    public function test_concurrent_migrate_waits_for_lock(): void
    {
        Config::set('migration_lock_timeout', 0);
        try {
            $this->write_migration('2026_01_01_000001_create_a.php', 'p_mig_a');
            $holder = $this->manager();
            $other = new MigrationManager($this->connect(), $this->dir . '/migrations', $this->dir . '/seeds');
            $other->set_migrations_table($this->log_table);

            // $holder kilidi tutarken $other çalışır; yakalanan hata dönüş değeriyle alınır
            $blocked = (fn () => $this->with_lock(static function () use ($other): ?MigrationException {
                try {
                    $other->migrate();

                    return null;
                } catch (MigrationException $e) {
                    return $e;
                }
            }))->call($holder);

            $this->assertInstanceOf(MigrationException::class, $blocked, 'Kilit tutulurken ikinci migrate() beklemeli / hata vermeli');
            $this->assertStringContainsString('kilidi', $blocked->getMessage());
            $this->assertFalse($this->table_exists('p_mig_a'));

            // Kilit bırakıldıktan sonra çalışır
            $this->assertSame(['2026_01_01_000001_create_a.php'], $other->migrate());
        } finally {
            Config::set('migration_lock_timeout', Config::migration_lock_timeout);
        }
    }

    public function test_failed_migration_is_rolled_back_where_ddl_is_transactional(): void
    {
        file_put_contents($this->dir . '/migrations/2026_01_01_000001_half.php', <<<'PHP'
<?php

return new class extends \nsql\database\BaseMigration {
    public function up(): void
    {
        $this->db()->query('CREATE TABLE p_mig_a (id INT PRIMARY KEY)');
        throw new \RuntimeException('yarıda kaldı');
    }
    public function down(): void { $this->db()->query('DROP TABLE IF EXISTS p_mig_a'); }
};
PHP);

        try {
            $this->manager()->migrate();
            $this->fail('Migration hata vermeli');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('yarıda kaldı', $e->getMessage());
        }

        $this->assertSame('failed', $this->manager()->get_status('2026_01_01_000001_half.php')['status'] ?? null);
        // MySQL DDL'de örtük commit yapar; PostgreSQL ve SQLite yarım DDL'i geri alır
        $this->assertSame(self::driver() === 'mysql', $this->table_exists('p_mig_a'));
    }

    public function test_targeted_migrate_partial_rollbacks_and_status_api(): void
    {
        $a = '2026_01_01_000001_create_a.php';
        $b = '2026_01_01_000002_create_b.php';
        $c = '2026_01_01_000003_create_c.php';
        $this->write_migration($a, 'p_mig_a');
        $this->write_migration($b, 'p_mig_b');
        $this->write_migration($c, 'p_mig_c');

        // Hedef dahil (v2.4.0+)
        $this->assertSame([$a, $b], $this->manager()->migrate_to($b));
        $report = $this->manager()->get_status_report();
        $this->assertSame(3, $report['total_migrations']);
        $this->assertSame(2, $report['applied_count']);
        $this->assertSame([$c], $report['pending_migrations']);
        $this->assertSame(1, $report['last_batch']);

        $this->assertSame([$c], $this->manager()->migrate());
        $this->assertSame(2, $this->manager()->get_batch_status(1)['migration_count']);
        $this->assertSame(1, $this->manager()->get_batch_status(2)['migration_count']);
        $history = $this->manager()->get_migration_history();
        $this->assertSame([1, 2], array_column($history, 'batch'));

        $this->assertSame([$c], $this->manager()->rollback_steps(1));
        $this->assertFalse($this->table_exists('p_mig_c'));
        $this->assertSame([$b], $this->manager()->rollback_to($a));
        $this->assertTrue($this->table_exists('p_mig_a'));
        $this->assertFalse($this->table_exists('p_mig_b'));

        $rolled_back = array_column($this->manager()->get_statuses_by_status('rolled_back'), 'migration_name');
        sort($rolled_back);
        $this->assertSame([$b, $c], $rolled_back);
        $this->assertCount(3, $this->manager()->get_all_statuses());

        $this->expectException(\InvalidArgumentException::class);
        $this->manager()->get_statuses_by_status('bilinmeyen');
    }

    public function test_errors_for_unknown_targets_and_batches(): void
    {
        $this->write_migration('2026_01_01_000001_create_a.php', 'p_mig_a');

        $calls = [
            fn () => $this->manager()->migrate_to('yok.php'),
            fn () => $this->manager()->rollback_to('2026_01_01_000001_create_a.php'),
            fn () => $this->manager()->get_batch_status(99),
            fn () => $this->manager()->rollback_steps(0),
        ];
        foreach ($calls as $i => $call) {
            try {
                $call();
                $this->fail("Durum #{$i} hata vermeli");
            } catch (\RuntimeException | \InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_dependencies_define_order(): void
    {
        // a, dosya sırasında önce gelse de b'ye bağımlı
        $this->write_migration('2026_01_01_000001_create_a.php', 'p_mig_a', ['2026_01_01_000002_create_b.php']);
        $this->write_migration('2026_01_01_000002_create_b.php', 'p_mig_b');

        $manager = $this->manager();
        $this->assertSame(['2026_01_01_000002_create_b.php', '2026_01_01_000001_create_a.php'], $manager->migrate());
        $this->assertSame(
            ['2026_01_01_000001_create_a.php' => ['2026_01_01_000002_create_b.php']],
            $manager->get_dependency_graph()
        );
        $this->assertFalse($manager->has_circular_dependency());

        // Geri alma ters sırada: önce bağımlı olan a, sonra b
        $this->assertSame(['2026_01_01_000001_create_a.php', '2026_01_01_000002_create_b.php'], $this->manager()->rollback());
        $this->assertFalse($this->table_exists('p_mig_b'));
    }

    public function test_circular_dependency_is_rejected(): void
    {
        $this->write_migration('2026_01_01_000001_create_a.php', 'p_mig_a', ['2026_01_01_000002_create_b.php']);
        $this->write_migration('2026_01_01_000002_create_b.php', 'p_mig_b', ['2026_01_01_000001_create_a.php']);

        $manager = $this->manager();
        $manager->load_migrations();
        $this->assertTrue($manager->has_circular_dependency());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Circular');
        $manager->migrate();
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
