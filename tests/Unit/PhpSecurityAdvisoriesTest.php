<?php

namespace Tests\Unit;

use nsql\security\PhpSecurityAdvisories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhpSecurityAdvisoriesTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string|null}>
     */
    public static function versions(): array
    {
        return [
            'PHP2Shell öncesi 8.4' => ['8.4.26', 'outdated', '8.4.27'],
            'yamalı 8.4' => ['8.4.27', 'ok', '8.4.27'],
            'yamalı 8.3' => ['8.3.36', 'ok', '8.3.36'],
            'eski 8.3 (dağıtım soneki)' => ['8.3.6-1ubuntu1', 'outdated', '8.3.36'],
            'yamalı 8.2' => ['8.2.40', 'ok', '8.2.35'],
            'yamalı 8.5' => ['8.5.12', 'ok', '8.5.12'],
            'desteği biten 8.1' => ['8.1.34', 'unsupported', '8.5.12'],
            'listeden yeni dal' => ['8.6.0RC1', 'ok', null],
        ];
    }

    #[DataProvider('versions')]
    public function test_status(string $version, string $status, ?string $minimum): void
    {
        $result = PhpSecurityAdvisories::status($version);

        $this->assertSame($status, $result['status']);
        $this->assertSame($minimum, $result['minimum']);
    }

    public function test_pdo_pgsql_quote_fix(): void
    {
        $this->assertFalse(PhpSecurityAdvisories::pdo_pgsql_quote_fixed('8.3.28'));
        $this->assertTrue(PhpSecurityAdvisories::pdo_pgsql_quote_fixed('8.3.29'));
        $this->assertFalse(PhpSecurityAdvisories::pdo_pgsql_quote_fixed('8.1.33'));
        $this->assertFalse(PhpSecurityAdvisories::pdo_pgsql_quote_fixed('8.0.30'));
        $this->assertTrue(PhpSecurityAdvisories::pdo_pgsql_quote_fixed('8.6.0'));
    }
}
