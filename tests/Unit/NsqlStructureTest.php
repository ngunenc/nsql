<?php

namespace Tests\Unit;

use nsql\database\nsql;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class NsqlStructureTest extends TestCase
{
    public function test_facade_stays_small(): void
    {
        $file = (string) (new ReflectionClass(nsql::class))->getFileName();

        $this->assertLessThan(800, count((array) file($file)));
    }

    public function test_class_does_not_redeclare_trait_members(): void
    {
        $class = new ReflectionClass(nsql::class);
        $trait_properties = [];
        $trait_methods = [];
        foreach ($class->getTraits() as $trait) {
            foreach ($trait->getProperties() as $property) {
                $trait_properties[$property->getName()][] = $trait->getShortName();
            }
            foreach ($trait->getMethods() as $method) {
                $trait_methods[$method->getName()][] = $trait->getShortName();
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
}
