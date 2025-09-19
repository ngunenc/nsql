<?php

namespace nsql\database;

class migration_manager
{
    private nsql $db;
    private array $migrations = [];
    private string $migrations_table = 'migrations';
    private string $migrations_path;
    private string $seeds_path;
    private bool $dry_run = false;
    private array $dependencies = [];

    public function __construct(nsql $db)
    {
        $this->db = $db;
        $this->migrations_path = __DIR__ . '/migrations';
        $this->seeds_path = __DIR__ . '/seeds';
        $this->init_migrations_table();
    }

    /**
     * Migrations tablosunu oluşturur
     */
    private function init_migrations_table(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->migrations_table} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration_name VARCHAR(255) NOT NULL,
            batch INT NOT NULL,
            status ENUM('pending', 'completed', 'failed') DEFAULT 'pending',
            error_message TEXT NULL,
            duration FLOAT NULL,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";

        $this->db->query($sql);
    }

    /**
     * Tüm migration dosyalarını yükler
     */
    public function load_migrations(): void
    {
        if (! is_dir($this->migrations_path)) {
            mkdir($this->migrations_path, 0755, true);
        }

        $files = glob($this->migrations_path . '/*.php');
        if ($files === false) {
            $files = [];
        }
        foreach ($files as $file) {
            require_once $file;
            $class_name = 'nsql\\database\\migrations\\' . basename($file, '.php');
            if (class_exists($class_name)) {
                $migration = new $class_name();
                if ($migration instanceof migration) {
                    $this->migrations[basename($file)] = $migration;
                }
            }
        }
    }

    /**
     * Çalıştırılmamış tüm migration'ları uygular
     */
    public function migrate(): array
    {
        $this->load_migrations();
        $executed = [];
        $batch = $this->get_next_batch();

        $applied = $this->get_applied_migrations();

        foreach ($this->migrations as $name => $migration) {
            if (! in_array($name, $applied)) {
                try {
                    $migration->up();
                    $this->log_migration($name, $batch, 'completed');
                    $executed[] = $name;
                } catch (\Exception $e) {
                    throw new \RuntimeException("Migration {$name} failed: " . $e->getMessage());
                }
            }
        }

        return $executed;
    }

    /**
     * Son batch'teki migration'ları geri alır
     */
    public function rollback(): array
    {
        $this->load_migrations();
        $rolled_back = [];

        $last_batch = $this->get_last_batch();
        if (! $last_batch) {
            return $rolled_back;
        }

        $migrations = $this->db->get_results(
            "SELECT migration_name FROM {$this->migrations_table} WHERE batch = :batch ORDER BY id DESC",
            ['batch' => $last_batch]
        );

        foreach ($migrations as $migration) {
            $name = $migration->migration_name;
            if (isset($this->migrations[$name])) {
                try {
                    $this->migrations[$name]->down();
                    $this->remove_migration($name);
                    $rolled_back[] = $name;
                } catch (\Exception $e) {
                    throw new \RuntimeException("Rollback {$name} failed: " . $e->getMessage());
                }
            }
        }

        return $rolled_back;
    }

    /**
     * Yeni bir migration dosyası oluşturur
     */
    public function create(string $name): string
    {
        $timestamp = date('Y_m_d_His');
        $filename = $timestamp . '_' . $name . '.php';
        $path = $this->migrations_path . '/' . $filename;

        if (! is_dir($this->migrations_path)) {
            mkdir($this->migrations_path, 0755, true);
        }

        $template = $this->get_migration_template($name);
        file_put_contents($path, $template);

        return $path;
    }

    private function get_migration_template(string $name): string
    {
        $class_name = str_replace(['-', ' '], '_', $name);

        return <<<PHP
<?php

namespace nsql\\database\\migrations;

use nsql\\database\\migration;

class {$class_name} implements migration {
    public function up(): void {
        // Migration kodunu buraya yazın
    }

    public function down(): void {
        // Geri alma kodunu buraya yazın
    }

    public function get_description(): string {
        return '{$name}';
    }
}
PHP;
    }

    private function get_next_batch(): int
    {
        $last_batch = $this->get_last_batch();

        return $last_batch + 1;
    }

    private function get_last_batch(): int
    {
        $result = $this->db->get_row(
            "SELECT MAX(batch) as last_batch FROM {$this->migrations_table}"
        );

        return $result && isset($result->last_batch) ? (int)$result->last_batch : 0;
    }

    private function get_applied_migrations(): array
    {
        $results = $this->db->get_results(
            "SELECT migration_name FROM {$this->migrations_table}"
        );

        return array_map(fn ($row) => $row->migration_name, $results);
    }

    /**
     * Dry-run modunu ayarlar
     */
    public function set_dry_run(bool $enabled): void
    {
        $this->dry_run = $enabled;
    }

    /**
     * Belirli bir sürüme kadar migration'ları çalıştırır
     */
    public function migrate_to(string $version): array
    {
        $this->load_migrations();
        $executed = [];
        $target_found = false;

        foreach ($this->migrations as $name => $migration) {
            if ($name === $version) {
                $target_found = true;

                break;
            }
        }

        if (! $target_found) {
            throw new \RuntimeException("Hedef versiyon bulunamadı: {$version}");
        }

        $batch = $this->get_next_batch();
        $applied = $this->get_applied_migrations();

        foreach ($this->migrations as $name => $migration) {
            if ($name === $version) {
                break;
            }

            if (! in_array($name, $applied)) {
                if ($this->check_dependencies($name)) {
                    $start_time = microtime(true);
                    try {

                        if (! $this->dry_run) {
                            $migration->up();
                            $duration = microtime(true) - $start_time;
                            $this->log_migration($name, $batch, 'completed', null, $duration);
                        }

                        $executed[] = $name;
                    } catch (\Exception $e) {
                        $duration = microtime(true) - $start_time;
                        $this->log_migration($name, $batch, 'failed', $e->getMessage(), $duration);

                        throw new \RuntimeException("Migration {$name} failed: " . $e->getMessage());
                    }
                } else {
                    throw new \RuntimeException("Bağımlılıklar karşılanmadı: {$name}");
                }
            }
        }

        return $executed;
    }

    /**
     * Migration bağımlılıklarını kontrol eder
     */
    private function check_dependencies(string $name): bool
    {
        if (! isset($this->dependencies[$name])) {
            return true;
        }

        $applied = $this->get_applied_migrations();
        foreach ($this->dependencies[$name] as $dependency) {
            if (! in_array($dependency, $applied)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Migration için bağımlılık ekler
     */
    public function add_dependency(string $migration, string $depends_on): void
    {
        if (! isset($this->dependencies[$migration])) {
            $this->dependencies[$migration] = [];
        }
        $this->dependencies[$migration][] = $depends_on;
    }

    /**
     * Seed verilerini yükler
     */
    public function seed(?string $class = null): void
    {
        if (! is_dir($this->seeds_path)) {
            mkdir($this->seeds_path, 0755, true);
        }

        if ($class === null) {
            $files = glob($this->seeds_path . '/*.php');
            if ($files === false) {
                $files = [];
            }
            foreach ($files as $file) {
                $this->run_seeder(basename($file, '.php'));
            }
        } else {
            $this->run_seeder($class);
        }
    }

    /**
     * Belirli bir seeder'ı çalıştırır
     */
    private function run_seeder(string $class): void
    {
        $file = $this->seeds_path . '/' . $class . '.php';
        if (! file_exists($file)) {
            throw new \RuntimeException("Seeder dosyası bulunamadı: {$class}");
        }

        require_once $file;
        $class_name = 'nsql\\database\\seeds\\' . $class;
        if (! class_exists($class_name)) {
            throw new \RuntimeException("Seeder sınıfı bulunamadı: {$class_name}");
        }

        $seeder = new $class_name();
        if (method_exists($seeder, 'run')) {
            $seeder->run($this->db);
        } else {
            throw new \RuntimeException("Seeder sınıfında run() metodu bulunamadı: {$class_name}");
        }
    }

    /**
     * Yeni bir seeder dosyası oluşturur
     */
    public function create_seeder(string $name): string
    {
        $filename = $name . '.php';
        $path = $this->seeds_path . '/' . $filename;

        if (! is_dir($this->seeds_path)) {
            mkdir($this->seeds_path, 0755, true);
        }

        $template = $this->get_seeder_template($name);
        file_put_contents($path, $template);

        return $path;
    }

    private function get_seeder_template(string $name): string
    {
        $class_name = str_replace(['-', ' '], '_', $name);

        return <<<PHP
<?php

namespace nsql\\database\\seeds;

use nsql\\database\\nsql;

class {$class_name} {
    public function run(nsql \$db): void {
        // Seed verilerini buraya ekleyin
    }
}
PHP;
    }

    private function log_migration(string $name, int $batch, string $status, ?string $error_message = null, ?float $duration = null): void
    {
        $params = [
            'name' => $name,
            'batch' => $batch,
            'status' => $status,
            'error_message' => $error_message,
            'duration' => $duration,
        ];

        $this->db->insert(
            "INSERT INTO {$this->migrations_table} (migration_name, batch, status, error_message, duration) 
             VALUES (:name, :batch, :status, :error_message, :duration)",
            $params
        );
    }

    private function remove_migration(string $name): void
    {
        $this->db->delete(
            "DELETE FROM {$this->migrations_table} WHERE migration_name = :name",
            ['name' => $name]
        );
    }
}
