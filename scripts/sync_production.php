<?php

/**
 * nsql dizin senkronizasyon script'i (geliştirme aracı)
 *
 * Kaynak ve hedef path zorunludur — placeholder yok.
 *
 * Kullanım:
 *   php scripts/sync_production.php --source=/path/dev --target=/path/prod [--dry-run]
 *   php scripts/sync_production.php --direction=to-production --target=/path/prod [--dry-run]
 *   NSQL_SYNC_SOURCE=... NSQL_SYNC_TARGET=... php scripts/sync_production.php --dry-run
 *
 * Ortam değişkenleri:
 *   NSQL_SYNC_SOURCE, NSQL_SYNC_TARGET  — --source / --target eşleniği
 *   NSQL_PRODUCTION_PATH                — --direction kullanıldığında diğer taraf
 *
 * Ayrıntılar: docs/sync-guide.md
 */

declare(strict_types=1);

$options = getopt('', [
    'source:',
    'target:',
    'direction:',
    'dry-run',
    'help',
]);

if (isset($options['help'])) {
    print_help();
    exit(0);
}

$project_root = dirname(__DIR__);
$dry_run = isset($options['dry-run']);

[$source, $target] = resolve_paths($options, $project_root);

if ($source === null || $target === null) {
    fwrite(STDERR, "Hata: --source ve --target (veya NSQL_SYNC_SOURCE / NSQL_SYNC_TARGET) gerekli.\n");
    fwrite(STDERR, "      --direction kullanıyorsanız NSQL_PRODUCTION_PATH veya --target/--source belirtin.\n\n");
    print_help();
    exit(1);
}

$source = realpath_or_path($source);
$target = realpath_or_path($target);

$sync_items = [
    'src/',
    'composer.json',
    'composer.lock',
    'README.md',
    'CHANGELOG.md',
];

$exclude_patterns = [
    '.git/',
    '.phpunit.cache/',
    'tests/',
    'benchmarks/',
    'docs/',
    '.github/',
    'node_modules/',
    'vendor/',
    'coverage/',
    '*.log',
    '.env',
    '.env.*',
];

echo "Senkronizasyon başlatılıyor...\n";
echo "Source:  $source\n";
echo "Target:  $target\n";
echo "Dry run: " . ($dry_run ? 'Evet' : 'Hayır') . "\n\n";

if (!is_dir($source)) {
    fwrite(STDERR, "Hata: Kaynak dizin bulunamadı: $source\n");
    exit(1);
}

if (!$dry_run && !is_dir($target)) {
    fwrite(STDERR, "Hata: Hedef dizin bulunamadı: $target\n");
    exit(1);
}

$synced_files = 0;
$errors = [];

foreach ($sync_items as $item) {
    $source_path = $source . DIRECTORY_SEPARATOR . rtrim($item, '/\\');
    $target_path = $target . DIRECTORY_SEPARATOR . rtrim($item, '/\\');

    if (!file_exists($source_path)) {
        echo "Uyarı: Kaynak bulunamadı: $source_path\n";
        continue;
    }

    if (is_dir($source_path)) {
        $result = sync_directory($source_path, $target_path, $exclude_patterns, $dry_run);
    } else {
        $result = sync_file($source_path, $target_path, $dry_run);
    }

    if ($result['success']) {
        $synced_files += $result['count'];
        echo "✓ $item senkronize edildi ({$result['count']} dosya)\n";
    } else {
        $errors[] = "$item: {$result['error']}";
        echo "✗ $item senkronize edilemedi: {$result['error']}\n";
    }
}

echo "\nSenkronizasyon tamamlandı.\n";
echo "Toplam senkronize edilen dosya: $synced_files\n";

if ($errors !== []) {
    echo "\nHatalar:\n";
    foreach ($errors as $error) {
        echo "  - $error\n";
    }
    exit(1);
}

exit(0);

/**
 * @param array<string, mixed> $options
 * @return array{0: ?string, 1: ?string}
 */
function resolve_paths(array $options, string $project_root): array
{
    $source = $options['source'] ?? getenv('NSQL_SYNC_SOURCE') ?: null;
    $target = $options['target'] ?? getenv('NSQL_SYNC_TARGET') ?: null;
    $production = getenv('NSQL_PRODUCTION_PATH') ?: null;
    $direction = $options['direction'] ?? null;

    if (is_string($source) && $source === '') {
        $source = null;
    }
    if (is_string($target) && $target === '') {
        $target = null;
    }
    if (is_string($production) && $production === '') {
        $production = null;
    }

    if ($direction !== null) {
        if (!in_array($direction, ['to-production', 'to-development'], true)) {
            fwrite(STDERR, "Hata: Geçersiz direction. 'to-production' veya 'to-development' kullanın.\n");
            exit(1);
        }

        if ($direction === 'to-production') {
            $source ??= $project_root;
            $target ??= $production;
        } else {
            $source ??= $production;
            $target ??= $project_root;
        }
    }

    return [
        is_string($source) ? $source : null,
        is_string($target) ? $target : null,
    ];
}

function realpath_or_path(string $path): string
{
    $resolved = realpath($path);

    return $resolved !== false ? $resolved : $path;
}

function print_help(): void
{
    echo <<<HELP
nsql dizin senkronizasyon script'i

Kullanım:
  php scripts/sync_production.php --source=DIR --target=DIR [--dry-run]
  php scripts/sync_production.php --direction=to-production --target=DIR [--dry-run]
  php scripts/sync_production.php --direction=to-development --source=DIR [--dry-run]

Seçenekler:
  --source=DIR                 Kaynak dizin (veya NSQL_SYNC_SOURCE)
  --target=DIR                 Hedef dizin (veya NSQL_SYNC_TARGET)
  --direction=to-production    Kaynak=bu repo, hedef=NSQL_PRODUCTION_PATH / --target
  --direction=to-development   Kaynak=NSQL_PRODUCTION_PATH / --source, hedef=bu repo
  --dry-run                    Simülasyon; dosya yazılmaz
  --help                       Bu yardım

Ortam:
  NSQL_SYNC_SOURCE, NSQL_SYNC_TARGET, NSQL_PRODUCTION_PATH

Dokümantasyon: docs/sync-guide.md

HELP;
}

/**
 * @param list<string> $exclude_patterns
 * @return array{success: bool, count: int, error?: string}
 */
function sync_directory(string $source, string $target, array $exclude_patterns, bool $dry_run): array
{
    $count = 0;

    if (!$dry_run && !is_dir($target)) {
        @mkdir($target, 0755, true);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        $relative_path = substr($file->getPathname(), strlen($source) + 1);

        $should_exclude = false;
        foreach ($exclude_patterns as $pattern) {
            if (fnmatch($pattern, $relative_path) || strpos($relative_path, $pattern) !== false) {
                $should_exclude = true;
                break;
            }
        }

        if ($should_exclude) {
            continue;
        }

        $target_file = $target . DIRECTORY_SEPARATOR . $relative_path;

        if ($file->isDir()) {
            if (!$dry_run && !is_dir($target_file)) {
                @mkdir($target_file, 0755, true);
            }
        } else {
            $result = sync_file($file->getPathname(), $target_file, $dry_run);
            if ($result['success']) {
                $count += $result['count'];
            }
        }
    }

    return ['success' => true, 'count' => $count];
}

/**
 * @return array{success: bool, count: int, error?: string}
 */
function sync_file(string $source, string $target, bool $dry_run): array
{
    if (!file_exists($source)) {
        return ['success' => false, 'error' => 'Kaynak dosya bulunamadı', 'count' => 0];
    }

    $target_dir = dirname($target);
    if (!$dry_run && !is_dir($target_dir)) {
        @mkdir($target_dir, 0755, true);
    }

    if (!$dry_run && file_exists($target)) {
        if (filemtime($source) <= filemtime($target) && filesize($source) === filesize($target)) {
            return ['success' => true, 'count' => 0];
        }
    }

    if ($dry_run) {
        return ['success' => true, 'count' => 1];
    }

    if (@copy($source, $target)) {
        @chmod($target, 0644);

        return ['success' => true, 'count' => 1];
    }

    return ['success' => false, 'error' => 'Dosya kopyalanamadı', 'count' => 0];
}
