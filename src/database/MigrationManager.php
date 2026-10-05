<?php

namespace nsql\database;

class MigrationManager
{
    private const DATE_PREFIX_PATTERN = '/^\d{4}_\d{2}_\d{2}_\d{6}_/';

    private const OPTIONAL_COLUMNS = [
        'error_message' => 'TEXT NULL',
        'duration' => 'FLOAT NULL',
        'rolled_back_at' => 'TIMESTAMP NULL',
        'rolled_back_by' => 'VARCHAR(255) NULL',
        'rollback_batch' => 'INT NULL',
    ];

    /**
     * require_once ikinci çağrıda dosyanın döndürdüğü nesneyi vermez; sonuçlar süreç boyunca saklanır.
     *
     * @var array<string, mixed>
     */
    private static array $included_files = [];

    private Nsql $db;
    private array $migrations = [];
    private string $migrations_table = 'migrations';
    private string $migrations_path;
    private string $seeds_path;
    private bool $dry_run = false;
    private bool $table_ready = false;
    private array $dependencies = [];

    /**
     * @param string|null $migrations_path null ise MIGRATIONS_PATH Config'i veya <proje kökü>/database/migrations
     * @param string|null $seeds_path null ise SEEDS_PATH Config'i veya <proje kökü>/database/seeds
     */
    public function __construct(Nsql $db, ?string $migrations_path = null, ?string $seeds_path = null)
    {
        $this->db = $db;
        $this->migrations_path = $this->resolve_path($migrations_path, 'MIGRATIONS_PATH', 'database/migrations');
        $this->seeds_path = $this->resolve_path($seeds_path, 'SEEDS_PATH', 'database/seeds');
    }

    public function get_migrations_path(): string
    {
        return $this->migrations_path;
    }

    public function get_seeds_path(): string
    {
        return $this->seeds_path;
    }

    public function set_migrations_table(string $table): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException("Geçersiz migrations tablo adı: {$table}");
        }

        $this->migrations_table = $table;
        $this->table_ready = false;
    }

    private function resolve_path(?string $path, string $config_key, string $default_relative): string
    {
        if ($path === null || trim($path) === '') {
            $configured = Config::get($config_key);
            $path = is_string($configured) && trim($configured) !== '' ? $configured : $default_relative;
        }

        $path = rtrim($path, '/\\');
        if (! self::is_absolute_path($path)) {
            $path = rtrim(Config::get_project_root(), '/\\') . DIRECTORY_SEPARATOR . $path;
        }

        return $path;
    }

    private static function is_absolute_path(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }

    /**
     * Migrations tablosunu ilk ihtiyaçta oluşturur; eski şemalarda eksik kolonları ekler.
     */
    private function ensure_migrations_table(): void
    {
        if ($this->table_ready) {
            return;
        }

        $driver = $this->db->get_driver_name();
        [$id, $status] = match ($driver) {
            'pgsql' => ['id SERIAL PRIMARY KEY', "status VARCHAR(20) DEFAULT 'pending'"],
            'sqlite' => ['id INTEGER PRIMARY KEY AUTOINCREMENT', "status VARCHAR(20) DEFAULT 'pending'"],
            default => ['id INT AUTO_INCREMENT PRIMARY KEY', "status ENUM('pending', 'completed', 'failed', 'rolled_back') DEFAULT 'pending'"],
        };
        $duration = $driver === 'pgsql' ? 'DOUBLE PRECISION NULL' : 'FLOAT NULL';

        $sql = "CREATE TABLE IF NOT EXISTS {$this->migrations_table} (
            {$id},
            migration_name VARCHAR(255) NOT NULL,
            batch INT NOT NULL,
            {$status},
            error_message TEXT NULL,
            duration {$duration},
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            rolled_back_at TIMESTAMP NULL,
            rolled_back_by VARCHAR(255) NULL,
            rollback_batch INT NULL
        )";

        $this->db->query($sql);
        $this->upgrade_migrations_table();
        $this->table_ready = true;
    }

    /**
     * Eski sürümlerde oluşturulmuş tabloya yalnızca eksik olan kolonları ekler.
     */
    private function upgrade_migrations_table(): void
    {
        $columns = $this->migrations_table_columns();

        foreach (self::OPTIONAL_COLUMNS as $name => $definition) {
            if (! isset($columns[$name])) {
                if ($name === 'duration' && $this->db->get_driver_name() === 'pgsql') {
                    $definition = 'DOUBLE PRECISION NULL';
                }
                $this->db->query("ALTER TABLE {$this->migrations_table} ADD COLUMN {$name} {$definition}");
            }
        }

        if ($this->db->get_driver_name() === 'mysql' && isset($columns['status']) && ! str_contains($columns['status'], 'rolled_back')) {
            $this->db->query("ALTER TABLE {$this->migrations_table} MODIFY COLUMN status ENUM('pending', 'completed', 'failed', 'rolled_back') DEFAULT 'pending'");
        }
    }

    /**
     * @return array<string, string> kolon adı => tip (küçük harf)
     */
    private function migrations_table_columns(): array
    {
        [$sql, $params, $name_key, $type_key] = match ($this->db->get_driver_name()) {
            'pgsql' => [
                'SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = :table',
                ['table' => $this->migrations_table],
                'column_name',
                'data_type',
            ],
            'sqlite' => ["PRAGMA table_info({$this->migrations_table})", [], 'name', 'type'],
            default => ["SHOW COLUMNS FROM {$this->migrations_table}", [], 'Field', 'Type'],
        };

        $columns = [];
        foreach ($this->db->get_results($sql, $params) as $column) {
            $columns[strtolower((string) $column->{$name_key})] = strtolower((string) $column->{$type_key});
        }

        return $columns;
    }

    /**
     * Tüm migration dosyalarını yükler.
     *
     * Dosya ya bir migration nesnesi döndürür (`return new class extends BaseMigration {...};`)
     * ya da tarih öneki çıkarılmış dosya adıyla aynı isimde bir sınıf tanımlar.
     */
    public function load_migrations(): void
    {
        $this->migrations = [];

        if (! is_dir($this->migrations_path)) {
            return;
        }

        $files = glob($this->migrations_path . '/*.php');
        if ($files === false) {
            $files = [];
        }
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            $migration_name = basename($file);
            $migration = $this->instantiate_from_file($file, Migration::class);
            if (! $migration instanceof Migration) {
                throw new \RuntimeException("Migration dosyası bir migration nesnesi veya sınıfı sağlamıyor: {$migration_name}");
            }

            if (method_exists($migration, 'set_connection')) {
                $migration->set_connection($this->db);
            }

            $this->migrations[$migration_name] = $migration;

            foreach ($migration->get_dependencies() as $dep) {
                $this->dependencies[$migration_name] ??= [];
                if (! in_array($dep, $this->dependencies[$migration_name], true)) {
                    $this->dependencies[$migration_name][] = $dep;
                }
            }
        }
    }

    /**
     * Dosyanın döndürdüğü nesneyi veya dosyada tanımlı sınıfın örneğini verir.
     */
    private function instantiate_from_file(string $file, string $expected_type): ?object
    {
        $key = realpath($file) ?: $file;
        if (! array_key_exists($key, self::$included_files)) {
            self::$included_files[$key] = (static fn (string $__file) => require_once $__file)($file);
        }

        $returned = self::$included_files[$key];
        if (is_object($returned)) {
            return $returned instanceof $expected_type || $expected_type === 'object' ? $returned : null;
        }

        foreach ($this->class_candidates($file) as $class_name) {
            if (class_exists($class_name, false)) {
                $instance = new $class_name();

                return $instance instanceof $expected_type || $expected_type === 'object' ? $instance : null;
            }
        }

        return null;
    }

    /**
     * @return array<string>
     */
    private function class_candidates(string $file): array
    {
        $base = basename($file, '.php');
        $short = preg_replace(self::DATE_PREFIX_PATTERN, '', $base) ?? $base;

        $namespace = '';
        $source = file_get_contents($file);
        if ($source !== false && preg_match('/^\s*namespace\s+([A-Za-z0-9_\\\\]+)\s*;/m', $source, $m)) {
            $namespace = $m[1] . '\\';
        }

        return array_values(array_unique([
            $namespace . $short,
            $namespace . $base,
            'nsql\\database\\migrations\\' . $short,
            'nsql\\database\\seeds\\' . $short,
        ]));
    }

    /**
     * Çalıştırılmamış tüm migration'ları uygular
     * Bağımlılıklara göre sıralanmış şekilde çalıştırır
     */
    public function migrate(): array
    {
        $this->ensure_migrations_table();
        $this->load_migrations();
        $executed = [];
        $batch = $this->get_next_batch();

        $applied = $this->get_applied_migrations();

        // Bağımlılık grafiğini oluştur ve sırala
        $sorted_migrations = $this->resolve_dependencies();

        // Circular dependency kontrolü
        if ($sorted_migrations === null) {
            throw new \RuntimeException('Circular dependency tespit edildi! Migration bağımlılıklarında döngü var.');
        }

        foreach ($sorted_migrations as $name) {
            if (! in_array($name, $applied)) {
                // Bağımlılıkların tamamlanmış olduğunu kontrol et
                if (! $this->check_dependencies($name, $this->dry_run ? $executed : [])) {
                    throw new \RuntimeException("Migration {$name} için bağımlılıklar karşılanmadı.");
                }

                if ($this->dry_run) {
                    $executed[] = $name;

                    continue;
                }

                $start_time = microtime(true);
                try {
                    $this->migrations[$name]->up();
                    $this->log_migration($name, $batch, 'completed', null, microtime(true) - $start_time);
                    $executed[] = $name;
                } catch (\Throwable $e) {
                    $this->log_migration($name, $batch, 'failed', $e->getMessage(), microtime(true) - $start_time);

                    throw new \RuntimeException("Migration {$name} failed: " . $e->getMessage(), 0, $e);
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
        return $this->rollback_batch(null);
    }

    /**
     * Belirli bir batch'teki migration'ları geri alır
     *
     * @param int|null $batch Batch numarası (null ise son batch)
     * @return array Geri alınan Migration'lar
     */
    public function rollback_batch(?int $batch = null): array
    {
        $this->ensure_migrations_table();
        $this->load_migrations();
        $rolled_back = [];

        if ($batch === null) {
            $batch = $this->get_last_batch();
        }

        if (! $batch) {
            return $rolled_back;
        }

        $migrations = $this->db->get_results(
            "SELECT migration_name, id FROM {$this->migrations_table} WHERE batch = :batch AND status = 'completed' ORDER BY id DESC",
            ['batch' => $batch]
        );

        foreach ($migrations as $migration) {
            $name = $migration->migration_name;
            if (isset($this->migrations[$name])) {
                // Bağımlılık kontrolü: Bu migration'a bağımlı olan migration'lar var mı?
                if ($this->has_dependents($name)) {
                    throw new \RuntimeException("Migration {$name} geri alınamaz: Bu migration'a bağımlı olan migration'lar var.");
                }

                try {
                    $this->migrations[$name]->down();
                    $this->log_rollback($name, $batch);
                    $rolled_back[] = $name;
                } catch (\Exception $e) {
                    throw new \RuntimeException("Rollback {$name} failed: " . $e->getMessage());
                }
            }
        }

        return $rolled_back;
    }

    /**
     * Belirli sayıda migration'ı geri alır
     *
     * @param int $steps Geri alınacak Migration sayısı
     * @return array Geri alınan Migration'lar
     */
    public function rollback_steps(int $steps): array
    {
        if ($steps <= 0) {
            throw new \InvalidArgumentException('Steps değeri pozitif olmalıdır.');
        }

        $this->ensure_migrations_table();
        $this->load_migrations();
        $rolled_back = [];

        // Tüm completed migration'ları batch'e göre sırala
        $migrations = $this->db->get_results(
            "SELECT migration_name, batch, id FROM {$this->migrations_table} WHERE status = 'completed' ORDER BY batch DESC, id DESC"
        );

        $count = 0;
        foreach ($migrations as $migration) {
            if ($count >= $steps) {
                break;
            }

            $name = $migration->migration_name;
            if (isset($this->migrations[$name])) {
                // Bağımlılık kontrolü
                if ($this->has_dependents($name)) {
                    throw new \RuntimeException("Migration {$name} geri alınamaz: Bu migration'a bağımlı olan migration'lar var.");
                }

                try {
                    $this->migrations[$name]->down();
                    $this->log_rollback($name, $migration->batch);
                    $rolled_back[] = $name;
                    $count++;
                } catch (\Exception $e) {
                    throw new \RuntimeException("Rollback {$name} failed: " . $e->getMessage());
                }
            }
        }

        return $rolled_back;
    }

    /**
     * Belirli bir migration'a kadar rollback yapar
     *
     * @param string $target_migration Hedef Migration dosya adı
     * @return array Geri alınan Migration'lar
     */
    public function rollback_to(string $target_migration): array
    {
        $this->ensure_migrations_table();
        $this->load_migrations();
        $rolled_back = [];

        // Hedef migration'ın var olup olmadığını kontrol et
        if (! isset($this->migrations[$target_migration])) {
            throw new \RuntimeException("Hedef migration bulunamadı: {$target_migration}");
        }

        // Hedef migration'ın uygulanmış olup olmadığını kontrol et
        $applied = $this->get_applied_migrations();
        if (! in_array($target_migration, $applied)) {
            throw new \RuntimeException("Hedef migration henüz uygulanmamış: {$target_migration}");
        }

        // Tüm completed migration'ları batch'e göre sırala
        $migrations = $this->db->get_results(
            "SELECT migration_name, batch, id FROM {$this->migrations_table} WHERE status = 'completed' ORDER BY batch DESC, id DESC"
        );

        $target_reached = false;
        foreach ($migrations as $migration) {
            if ($target_reached) {
                break;
            }

            $name = $migration->migration_name;

            // Hedef migration'a ulaştık mı?
            if ($name === $target_migration) {
                $target_reached = true;
                continue; // Hedef migration'ı geri alma, sadece ondan sonrakileri geri al
            }

            if (isset($this->migrations[$name])) {
                // Bağımlılık kontrolü
                if ($this->has_dependents($name)) {
                    throw new \RuntimeException("Migration {$name} geri alınamaz: Bu migration'a bağımlı olan migration'lar var.");
                }

                try {
                    $this->migrations[$name]->down();
                    $this->log_rollback($name, $migration->batch);
                    $rolled_back[] = $name;
                } catch (\Exception $e) {
                    throw new \RuntimeException("Rollback {$name} failed: " . $e->getMessage());
                }
            }
        }

        return $rolled_back;
    }

    /**
     * Bir migration'ın bağımlıları (dependents) var mı kontrol eder
     *
     * @param string $migration_name Migration adı
     * @return bool Bağımlıları varsa true
     */
    private function has_dependents(string $migration_name): bool
    {
        $applied = $this->get_applied_migrations();

        foreach ($this->dependencies as $dependent => $deps) {
            // Eğer bu dependent uygulanmışsa ve bağımlılıkları arasında bu migration varsa
            if (in_array($dependent, $applied) && in_array($migration_name, $deps)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rollback işlemini loglar
     *
     * @param string $name Migration adı
     * @param int $batch Batch numarası
     * @param string|null $rolled_back_by Kim tarafından geri alındı (opsiyonel)
     */
    private function log_rollback(string $name, int $batch, ?string $rolled_back_by = null): void
    {
        $rollback_batch = $this->get_next_rollback_batch();

        $this->db->update(
            "UPDATE {$this->migrations_table} 
             SET status = 'rolled_back', 
                 rolled_back_at = CURRENT_TIMESTAMP, 
                 rolled_back_by = :rolled_back_by,
                 rollback_batch = :rollback_batch
             WHERE migration_name = :name AND batch = :batch",
            [
                'name' => $name,
                'batch' => $batch,
                'rolled_back_by' => $rolled_back_by ?? 'system',
                'rollback_batch' => $rollback_batch,
            ]
        );
    }

    /**
     * Son rollback batch numarasını döndürür
     */
    private function get_next_rollback_batch(): int
    {
        $result = $this->db->get_row(
            "SELECT MAX(rollback_batch) as last_rollback_batch FROM {$this->migrations_table} WHERE rollback_batch IS NOT NULL"
        );

        return $result && isset($result->last_rollback_batch) ? (int)$result->last_rollback_batch + 1 : 1;
    }

    /**
     * Yeni bir migration dosyası oluşturur
     */
    public function create(string $name): string
    {
        $this->assert_valid_file_name($name);
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

    private function assert_valid_file_name(string $name): void
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException("Geçersiz ad: {$name} (yalnızca harf, rakam ve _ kullanılabilir)");
        }
    }

    private function get_migration_template(string $name): string
    {
        $description = var_export($name, true);

        return <<<PHP
<?php

use nsql\\database\\BaseMigration;

return new class extends BaseMigration {
    public function up(): void
    {
        // \$this->db()->query('CREATE TABLE ...');
    }

    public function down(): void
    {
        // \$this->db()->query('DROP TABLE IF EXISTS ...');
    }

    public function get_description(): string
    {
        return {$description};
    }

    public function get_dependencies(): array
    {
        // Bağımlı olunan migration dosya adları, örn. ['2025_05_24_000001_create_users_table.php']
        return [];
    }
};

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
            "SELECT migration_name FROM {$this->migrations_table} WHERE status = 'completed'"
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
        $this->ensure_migrations_table();
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
    /**
     * @param array<string> $also_applied Dry-run sırasında uygulanmış sayılacak Migration'lar
     */
    private function check_dependencies(string $name, array $also_applied = []): bool
    {
        if (! isset($this->dependencies[$name])) {
            return true;
        }

        $applied = array_merge($this->get_applied_migrations(), $also_applied);
        foreach ($this->dependencies[$name] as $dependency) {
            if (! in_array($dependency, $applied)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Bağımlılık grafiğini çözümler ve topological sort yapar
     *
     * @return array<string>|null Sıralanmış Migration adları veya null (circular dependency varsa)
     */
    private function resolve_dependencies(): ?array
    {
        $graph = [];
        $in_degree = [];

        // Tüm migration'ları başlat
        foreach ($this->migrations as $name => $migration) {
            $graph[$name] = [];
            $in_degree[$name] = 0;
        }

        // Bağımlılık grafiğini oluştur
        foreach ($this->dependencies as $migration => $deps) {
            if (! isset($this->migrations[$migration])) {
                continue; // Migration yüklenmemiş
            }

            foreach ($deps as $dep) {
                if (isset($this->migrations[$dep])) {
                    $graph[$dep][] = $migration; // dep -> migration (dep çalışmalı ki migration çalışsın)
                    $in_degree[$migration]++;
                }
            }
        }

        // Topological sort (Kahn's algorithm)
        $queue = [];
        $result = [];

        // İn-degree 0 olanları bul
        foreach ($in_degree as $name => $degree) {
            if ($degree === 0) {
                $queue[] = $name;
            }
        }

        while (! empty($queue)) {
            $current = array_shift($queue);
            $result[] = $current;

            // Bu migration'a bağımlı olanları güncelle
            foreach ($graph[$current] as $dependent) {
                $in_degree[$dependent]--;
                if ($in_degree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        // Circular dependency kontrolü: Eğer tüm migration'lar sıralanmadıysa döngü var
        if (count($result) !== count($this->migrations)) {
            return null;
        }

        return $result;
    }

    /**
     * Circular dependency kontrolü yapar
     *
     * @return bool Circular dependency varsa true
     */
    public function has_circular_dependency(): bool
    {
        $sorted = $this->resolve_dependencies();
        return $sorted === null;
    }

    /**
     * Migration bağımlılık grafiğini döndürür
     *
     * @return array<string, array<string>> Migration adı => bağımlılık listesi
     */
    public function get_dependency_graph(): array
    {
        return $this->dependencies;
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
        if ($class === null) {
            if (! is_dir($this->seeds_path)) {
                return;
            }

            $files = glob($this->seeds_path . '/*.php');
            if ($files === false) {
                $files = [];
            }
            sort($files, SORT_STRING);
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
        $this->assert_valid_file_name($class);
        $file = $this->seeds_path . '/' . $class . '.php';
        if (! file_exists($file)) {
            throw new \RuntimeException("Seeder dosyası bulunamadı: {$class}");
        }

        $seeder = $this->instantiate_from_file($file, 'object');
        if ($seeder === null) {
            throw new \RuntimeException("Seeder dosyası bir nesne veya sınıf sağlamıyor: {$class}");
        }
        if (! method_exists($seeder, 'run')) {
            throw new \RuntimeException("Seeder sınıfında run() metodu bulunamadı: {$class}");
        }

        $seeder->run($this->db);
    }

    /**
     * Yeni bir seeder dosyası oluşturur
     */
    public function create_seeder(string $name): string
    {
        $this->assert_valid_file_name($name);
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
        return <<<PHP
<?php

use nsql\\database\\Nsql;

return new class {
    public function run(Nsql \$db): void
    {
        // Seed verilerini buraya ekleyin
    }
};

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

    /**
     * Migration status API'si
     */

    /**
     * Belirli bir migration'ın durumunu döndürür
     *
     * @param string $migration_name Migration dosya adı
     * @return array|null Migration durumu veya null (bulunamazsa)
     */
    public function get_status(string $migration_name): ?array
    {
        $this->ensure_migrations_table();
        $result = $this->db->get_row(
            "SELECT * FROM {$this->migrations_table} WHERE migration_name = :name",
            ['name' => $migration_name]
        );

        if (! $result) {
            return null;
        }

        return [
            'migration_name' => $result->migration_name,
            'status' => $result->status,
            'batch' => (int)$result->batch,
            'executed_at' => $result->executed_at,
            'rolled_back_at' => $result->rolled_back_at,
            'rolled_back_by' => $result->rolled_back_by,
            'rollback_batch' => $result->rollback_batch ? (int)$result->rollback_batch : null,
            'duration' => $result->duration ? (float)$result->duration : null,
            'error_message' => $result->error_message,
        ];
    }

    /**
     * Tüm migration'ların durumunu döndürür
     *
     * @return array Migration durumları
     */
    public function get_all_statuses(): array
    {
        $this->ensure_migrations_table();
        $results = $this->db->get_results(
            "SELECT * FROM {$this->migrations_table} ORDER BY batch ASC, id ASC"
        );

        $statuses = [];
        foreach ($results as $result) {
            $statuses[] = [
                'migration_name' => $result->migration_name,
                'status' => $result->status,
                'batch' => (int)$result->batch,
                'executed_at' => $result->executed_at,
                'rolled_back_at' => $result->rolled_back_at,
                'rolled_back_by' => $result->rolled_back_by,
                'rollback_batch' => $result->rollback_batch ? (int)$result->rollback_batch : null,
                'duration' => $result->duration ? (float)$result->duration : null,
                'error_message' => $result->error_message,
            ];
        }

        return $statuses;
    }

    /**
     * Belirli bir status'e sahip migration'ları döndürür
     *
     * @param string $status Status (pending, completed, failed, rolled_back)
     * @return array Migration durumları
     */
    public function get_statuses_by_status(string $status): array
    {
        $valid_statuses = ['pending', 'completed', 'failed', 'rolled_back'];
        if (! in_array($status, $valid_statuses)) {
            throw new \InvalidArgumentException("Geçersiz status: {$status}");
        }

        $this->ensure_migrations_table();
        $results = $this->db->get_results(
            "SELECT * FROM {$this->migrations_table} WHERE status = :status ORDER BY batch ASC, id ASC",
            ['status' => $status]
        );

        $statuses = [];
        foreach ($results as $result) {
            $statuses[] = [
                'migration_name' => $result->migration_name,
                'status' => $result->status,
                'batch' => (int)$result->batch,
                'executed_at' => $result->executed_at,
                'rolled_back_at' => $result->rolled_back_at,
                'rolled_back_by' => $result->rolled_back_by,
                'rollback_batch' => $result->rollback_batch ? (int)$result->rollback_batch : null,
                'duration' => $result->duration ? (float)$result->duration : null,
                'error_message' => $result->error_message,
            ];
        }

        return $statuses;
    }

    /**
     * Migration geçmişini döndürür (tüm batch'ler)
     *
     * @return array Migration geçmişi (batch'lere göre gruplanmış)
     */
    public function get_migration_history(): array
    {
        $this->ensure_migrations_table();
        $results = $this->db->get_results(
            "SELECT * FROM {$this->migrations_table} ORDER BY batch ASC, id ASC"
        );

        $history = [];
        foreach ($results as $result) {
            $batch = (int)$result->batch;
            if (! isset($history[$batch])) {
                $history[$batch] = [
                    'batch' => $batch,
                    'migrations' => [],
                ];
            }

            $history[$batch]['migrations'][] = [
                'migration_name' => $result->migration_name,
                'status' => $result->status,
                'executed_at' => $result->executed_at,
                'rolled_back_at' => $result->rolled_back_at,
                'rolled_back_by' => $result->rolled_back_by,
                'rollback_batch' => $result->rollback_batch ? (int)$result->rollback_batch : null,
                'duration' => $result->duration ? (float)$result->duration : null,
                'error_message' => $result->error_message,
            ];
        }

        return array_values($history);
    }

    /**
     * Migration status raporu oluşturur
     *
     * @return array Status raporu
     */
    public function get_status_report(): array
    {
        $this->ensure_migrations_table();
        $this->load_migrations();

        $total_migrations = count($this->migrations);
        $applied = $this->get_applied_migrations();
        $applied_count = count($applied);

        $status_counts = $this->db->get_results(
            "SELECT status, COUNT(*) as count FROM {$this->migrations_table} GROUP BY status"
        );

        $counts = [
            'pending' => 0,
            'completed' => 0,
            'failed' => 0,
            'rolled_back' => 0,
        ];

        foreach ($status_counts as $row) {
            $counts[$row->status] = (int)$row->count;
        }

        $pending_migrations = [];
        foreach ($this->migrations as $name => $migration) {
            if (! in_array($name, $applied)) {
                $pending_migrations[] = $name;
            }
        }

        return [
            'total_migrations' => $total_migrations,
            'applied_count' => $applied_count,
            'pending_count' => count($pending_migrations),
            'status_counts' => $counts,
            'pending_migrations' => $pending_migrations,
            'last_batch' => $this->get_last_batch(),
            'last_rollback_batch' => $this->get_next_rollback_batch() - 1,
        ];
    }

    /**
     * Belirli bir batch'in durumunu döndürür
     *
     * @param int $batch Batch numarası
     * @return array Batch durumu
     */
    public function get_batch_status(int $batch): array
    {
        $this->ensure_migrations_table();
        $results = $this->db->get_results(
            "SELECT * FROM {$this->migrations_table} WHERE batch = :batch ORDER BY id ASC",
            ['batch' => $batch]
        );

        if (empty($results)) {
            throw new \RuntimeException("Batch {$batch} bulunamadı.");
        }

        $migrations = [];
        foreach ($results as $result) {
            $migrations[] = [
                'migration_name' => $result->migration_name,
                'status' => $result->status,
                'executed_at' => $result->executed_at,
                'rolled_back_at' => $result->rolled_back_at,
                'rolled_back_by' => $result->rolled_back_by,
                'rollback_batch' => $result->rollback_batch ? (int)$result->rollback_batch : null,
                'duration' => $result->duration ? (float)$result->duration : null,
                'error_message' => $result->error_message,
            ];
        }

        return [
            'batch' => $batch,
            'migration_count' => count($migrations),
            'migrations' => $migrations,
        ];
    }
}
