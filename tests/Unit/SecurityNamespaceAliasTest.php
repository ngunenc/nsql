<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * #25: web güvenlik katmanı nsql\security altına taşındı; eski adlar 1.x boyunca takma ad olarak çalışır.
 */
class SecurityNamespaceAliasTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function moved(): array
    {
        $names = ['security_manager', 'session_manager', 'rate_limiter', 'ip_resolver', 'encryption', 'key_manager', 'audit_logger'];

        return array_combine($names, array_map(fn ($n) => [$n], $names));
    }

    #[DataProvider('moved')]
    public function test_legacy_name_is_alias_of_new_class(string $name): void
    {
        $legacy = 'nsql\\database\\security\\' . $name;
        $current = 'nsql\\security\\' . $name;

        $this->assertTrue(class_exists($legacy));
        $this->assertSame($current, (new \ReflectionClass($legacy))->getName());
    }

    public function test_instances_are_interchangeable(): void
    {
        $legacy_resolver = 'nsql\\database\\security\\ip_resolver';
        $legacy_manager = 'nsql\\database\\security\\security_manager';
        $resolver = new $legacy_resolver([], ['REMOTE_ADDR' => '10.0.0.1']);

        $this->assertInstanceOf(\nsql\security\ip_resolver::class, $resolver);
        $this->assertSame('10.0.0.1', $resolver->client_ip());
        $this->assertSame('&lt;b&gt;', $legacy_manager::escape_html('<b>'));
    }

    public function test_core_security_classes_stay_in_database_namespace(): void
    {
        $this->assertTrue(class_exists(\nsql\database\security\query_analyzer::class));
        $this->assertTrue(class_exists(\nsql\database\security\sensitive_data_filter::class));
        $this->assertFalse(class_exists('nsql\\security\\sensitive_data_filter'));
    }
}
