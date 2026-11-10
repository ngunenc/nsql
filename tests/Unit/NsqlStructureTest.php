<?php

namespace Tests\Unit;

use nsql\database\Nsql;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class NsqlStructureTest extends TestCase
{
    public function test_facade_stays_small(): void
    {
        $file = (string) (new ReflectionClass(Nsql::class))->getFileName();

        $this->assertLessThan(800, count((array) file($file)));
    }

    public function test_class_does_not_redeclare_trait_members(): void
    {
        $class = new ReflectionClass(Nsql::class);
        $trait_properties = [];
        $trait_methods = [];
        foreach ($class->getTraits() as $trait) {
            foreach ($trait->getProperties() as $property) {
                $trait_properties[$property->getName()][] = $trait->getShortName();
            }
            foreach ($trait->getMethods() as $method) {
                // abstract bildirim başka trait'e bağımlılık sözleşmesidir, kopya tanım değil
                if (! $method->isAbstract()) {
                    $trait_methods[$method->getName()][] = $trait->getShortName();
                }
            }
        }

        $source = (string) file_get_contents((string) $class->getFileName());
        foreach (array_keys($trait_properties) as $name) {
            $this->assertDoesNotMatchRegularExpression('/(private|protected|public)[^;(]*\$' . $name . '\b/', $source, "nsql::\${$name} trait ile çakışıyor");
        }
        foreach (array_keys($trait_methods) as $name) {
            $this->assertStringNotContainsString("function {$name}(", $source, "nsql::{$name}() trait ile çakışıyor");
        }

        foreach ($trait_methods as $name => $owners) {
            $this->assertCount(1, $owners, "{$name}() birden fazla trait'te: " . implode(', ', $owners));
        }
    }

    /**
     * Hiçbir yerden çağrılmayan private debug şablon yolu kaldırıldı (debug() çıktıyı kendi üretir).
     */
    public function test_unused_debug_template_path_is_removed(): void
    {
        $class = new ReflectionClass(Nsql::class);

        $this->assertFalse($class->hasMethod('render_debug_output'));
        $this->assertFalse($class->hasMethod('handle_debug_error'));
        $this->assertFileDoesNotExist(dirname((string) $class->getFileName()) . '/templates/debug_template.php');
    }

    /**
     * Kütüphanenin kullanmadığı protected yardımcılar alt sınıflar çağırabileceği için silinmedi,
     * kullanımdan kaldırıldı olarak işaretlendi.
     */
    public function test_unused_protected_error_helpers_are_deprecated(): void
    {
        $class = new ReflectionClass(Nsql::class);

        foreach (['safe_execute_operation', 'execute_with_retry', 'is_recoverable_error'] as $name) {
            $this->assertTrue($class->hasMethod($name), $name);
            $this->assertStringContainsString('@deprecated', (string) $class->getMethod($name)->getDocComment(), $name);
        }
    }
}
