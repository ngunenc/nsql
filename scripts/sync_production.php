<?php

/**
 * Production-Development Senkronizasyon Script'i
 * 
 * Bu script production (diger/nsql) ve development arasında senkronizasyon sağlar
 * 
 * Kullanım:
 *   php scripts/sync_production.php --direction=to-production
 *   php scripts/sync_production.php --direction=to-development
 */

$options = getopt('', ['direction:', 'dry-run', 'help']);

if (isset($options['help']) || !isset($options['direction'])) {
    echo "Production-Development Senkronizasyon Script'i\n\n";
    echo "Kullanım:\n";
    echo "  php scripts/sync_production.php --direction=to-production [--dry-run]\n";
    echo "  php scripts/sync_production.php --direction=to-development [--dry-run]\n\n";
    echo "Seçenekler:\n";
    echo "  --direction=to-production    Development'dan Production'a senkronize et\n";
    echo "  --direction=to-development   Production'dan Development'a senkronize et\n";
    echo "  --dry-run                    Sadece simülasyon yap, değişiklik yapma\n";
    echo "  --help                       Bu yardım mesajını göster\n";
    exit(0);
}

$direction = $options['direction'];
$dry_run = isset($options['dry-run']);

if (!in_array($direction, ['to-production', 'to-development'])) {
    echo "Hata: Geçersiz direction. 'to-production' veya 'to-development' kullanın.\n";
    exit(1);
}

// Proje kök dizini
$project_root = dirname(__DIR__);
$production_path = '/path/to/production/nsql'; // Production path'i buraya ekleyin
$development_path = $project_root;

// Senkronize edilecek dosya/dizinler
$sync_items = [
    'src/',
    'composer.json',
    'composer.lock',
    'README.md',
    'CHANGELOG.md',
    'INSTALLATION.md',
];

// Hariç tutulacak dosya/dizinler
$exclude_patterns = [
    '.git/',
    '.phpunit.cache/',
    'tests/',
    'benchmarks/',
    'docs/',
    '.github/',
    'node_modules/',
    'vendor/',
    '*.log',
    '.env',
    '.env.*',
];

echo "Senkronizasyon başlatılıyor...\n";
echo "Direction: $direction\n";
echo "Dry run: " . ($dry_run ? 'Evet' : 'Hayır') . "\n\n";

if ($direction === 'to-production') {
    $source = $development_path;
    $target = $production_path;
} else {
    $source = $production_path;
    $target = $development_path;
}

if (!is_dir($source)) {
    echo "Hata: Kaynak dizin bulunamadı: $source\n";
    exit(1);
}

if (!$dry_run && !is_dir($target)) {
    echo "Hata: Hedef dizin bulunamadı: $target\n";
    exit(1);
}

$synced_files = 0;
$errors = [];

foreach ($sync_items as $item) {
    $source_path = $source . DIRECTORY_SEPARATOR . $item;
    $target_path = $target . DIRECTORY_SEPARATOR . $item;
    
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

if (!empty($errors)) {
    echo "\nHatalar:\n";
    foreach ($errors as $error) {
        echo "  - $error\n";
    }
    exit(1);
}

/**
 * Dizini senkronize eder
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
        $relative_path = str_replace($source . DIRECTORY_SEPARATOR, '', $file->getPathname());
        
        // Exclude pattern kontrolü
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
                $count++;
            }
        }
    }
    
    return ['success' => true, 'count' => $count];
}

/**
 * Dosyayı senkronize eder
 */
function sync_file(string $source, string $target, bool $dry_run): array
{
    if (!file_exists($source)) {
        return ['success' => false, 'error' => 'Kaynak dosya bulunamadı', 'count' => 0];
    }
    
    // Hedef dizini oluştur
    $target_dir = dirname($target);
    if (!$dry_run && !is_dir($target_dir)) {
        @mkdir($target_dir, 0755, true);
    }
    
    // Dosya değişiklik kontrolü
    if (!$dry_run && file_exists($target)) {
        if (filemtime($source) <= filemtime($target) && filesize($source) === filesize($target)) {
            // Dosya güncel, senkronize etme
            return ['success' => true, 'count' => 0];
        }
    }
    
    if ($dry_run) {
        return ['success' => true, 'count' => 1];
    }
    
    // Dosyayı kopyala
    if (@copy($source, $target)) {
        @chmod($target, 0644);
        return ['success' => true, 'count' => 1];
    }
    
    return ['success' => false, 'error' => 'Dosya kopyalanamadı', 'count' => 0];
}
