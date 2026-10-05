<?php

/**
 * Clover raporundan satır coverage'ını okur; eşiğin altındaysa 1 ile çıkar.
 *
 *   php tests/coverage_check.php coverage/clover.xml 50 [--lowest=10]
 */

$file = $argv[1] ?? 'coverage/clover.xml';
$threshold = (float) ($argv[2] ?? 50);
$lowest = 0;
foreach (array_slice($argv, 3) as $arg) {
    if (str_starts_with($arg, '--lowest=')) {
        $lowest = (int) substr($arg, 9);
    }
}

if (! is_file($file)) {
    fwrite(STDERR, "Clover raporu bulunamadı: {$file}\n");
    exit(1);
}

$xml = simplexml_load_file($file);
if ($xml === false) {
    fwrite(STDERR, "Clover raporu okunamadı: {$file}\n");
    exit(1);
}

$metrics = $xml->project->metrics;
$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$percent = $statements > 0 ? 100 * $covered / $statements : 0.0;

if ($lowest > 0) {
    $rows = [];
    $root = dirname(__DIR__) . DIRECTORY_SEPARATOR;
    foreach ($xml->xpath('//file') ?: [] as $node) {
        $count = (int) $node->metrics['statements'];
        if ($count >= 20) {
            $rows[] = [100 * (int) $node->metrics['coveredstatements'] / $count, $count, str_replace($root, '', (string) $node['name'])];
        }
    }
    usort($rows, fn ($a, $b) => $a[0] <=> $b[0]);
    foreach (array_slice($rows, 0, $lowest) as [$p, $count, $name]) {
        printf("%6.1f%%  %5d  %s\n", $p, $count, $name);
    }
}

printf("Satır coverage: %.2f%% (%d/%d), eşik %.0f%%\n", $percent, $covered, $statements, $threshold);
exit($percent + 1e-9 >= $threshold ? 0 : 1);
