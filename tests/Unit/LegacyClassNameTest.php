<?php

namespace Tests\Unit;

use nsql\database\QueryBuilder;
use nsql\security\IpResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 2.0 (#24): 1.x snake_case sınıf adları legacy_autoload.php ile 2.x boyunca çalışır;
 * 1.x'te deprecated olan takma adlar (#25 nsql\database\security\*, model_not_found_exception) kaldırıldı.
 */
class LegacyClassNameTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function legacy_names(): array
    {
        $map = require dirname(__DIR__, 2) . '/src/legacy_class_map.php';
        $cases = [];
        foreach ($map as $old => $new) {
            $cases[$old] = [$old, $new];
        }

        return $cases;
    }

    #[DataProvider('legacy_names')]
    public function test_legacy_name_resolves_to_pascal_case_type(string $old, string $new): void
    {
        $exists = static fn (string $name): bool => class_exists($name) || interface_exists($name) || trait_exists($name);

        $this->assertTrue($exists($new), "{$new} yüklenemedi");
        $this->assertTrue($exists($old), "{$old} yüklenemedi");
        $this->assertSame($new, (new \ReflectionClass($old))->getName());
    }

    public function test_legacy_instances_are_interchangeable(): void
    {
        $legacy_resolver = 'nsql\\security\\ip_resolver';
        $resolver = new $legacy_resolver([], ['REMOTE_ADDR' => '10.0.0.1']);

        $this->assertInstanceOf(IpResolver::class, $resolver);
        $this->assertSame('10.0.0.1', $resolver->client_ip());
        $this->assertTrue(is_a(QueryBuilder::class, 'nsql\\database\\query_builder', true));

        $legacy_manager = 'nsql\\security\\security_manager';
        $this->assertSame('&lt;b&gt;', $legacy_manager::escape_html('<b>'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function removed_aliases(): array
    {
        $names = [
            'nsql\\database\\security\\security_manager',
            'nsql\\database\\security\\session_manager',
            'nsql\\database\\security\\rate_limiter',
            'nsql\\database\\security\\ip_resolver',
            'nsql\\database\\security\\encryption',
            'nsql\\database\\security\\key_manager',
            'nsql\\database\\security\\audit_logger',
            'nsql\\database\\orm\\model_not_found_exception',
        ];

        return array_combine($names, array_map(fn ($n) => [$n], $names));
    }

    #[DataProvider('removed_aliases')]
    public function test_aliases_deprecated_in_1x_are_removed(string $name): void
    {
        $this->assertFalse(class_exists($name));
    }

    public function test_core_security_classes_stay_in_database_namespace(): void
    {
        $this->assertTrue(class_exists(\nsql\database\security\QueryAnalyzer::class));
        $this->assertTrue(class_exists(\nsql\database\security\SensitiveDataFilter::class));
        $this->assertFalse(class_exists('nsql\\security\\sensitive_data_filter'));
    }

    public function test_unknown_snake_case_names_are_not_aliased(): void
    {
        $this->assertFalse(class_exists('nsql\\database\\does_not_exist'));
    }
}
