<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #24 isimlendirme politikası (CONTRIBUTING.md): 2.0'dan itibaren tüm sınıf/interface/trait adları PascalCase,
 * exception'lar `Exception` son ekli; dosya adı sınıf adıyla aynı (PSR-4).
 */
class NamingPolicyTest extends TestCase
{
    /**
     * @return array<string, string> dosya yolu => içerik
     */
    private static function sources(): array
    {
        $root = dirname(__DIR__, 2) . '/src';
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1))] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($files);

        return $files;
    }

    public function test_exception_classes_are_pascal_case(): void
    {
        $violations = [];
        foreach (self::sources() as $path => $code) {
            if (preg_match_all('/^\s*(?:final\s+|abstract\s+)?class\s+(\w+)\s+extends\s+([\\\\\w]+)/m', $code, $matches, PREG_SET_ORDER)) {
                foreach ($matches as [, $class, $parent]) {
                    $is_exception = str_ends_with(strtolower($class), 'exception') || str_ends_with($parent, 'Exception');
                    if ($is_exception && ! preg_match('/^[A-Z][A-Za-z0-9]*Exception$/', $class)) {
                        $violations[] = "{$path}: {$class}";
                    }
                }
            }
        }

        $this->assertSame([], $violations);
    }

    public function test_all_classes_interfaces_and_traits_are_pascal_case(): void
    {
        $violations = [];
        foreach (self::sources() as $path => $code) {
            if (! preg_match('/^\s*(?:final\s+|abstract\s+)?(?:class|interface|trait|enum)\s+(\w+)/m', $code, $m)) {
                continue;
            }
            if (! preg_match('/^[A-Z][A-Za-z0-9]*$/', $m[1])) {
                $violations[] = "{$path}: {$m[1]}";
            }
        }

        $this->assertSame([], $violations);
    }

    public function test_declared_class_matches_file_name(): void
    {
        $violations = [];
        foreach (self::sources() as $path => $code) {
            if (! preg_match('/^\s*(?:final\s+|abstract\s+)?(?:class|interface|trait|enum)\s+(\w+)/m', $code, $m)) {
                continue;
            }
            if ($m[1] !== basename($path, '.php')) {
                $violations[] = "{$path}: {$m[1]}";
            }
        }

        $this->assertSame([], $violations);
    }
}
