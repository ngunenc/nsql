<?php

namespace Tests\Unit;

use nsql\database\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * upsert(): MySQL 8.0.19+ satır takma adı, MariaDB / eski MySQL VALUES() (#91).
 */
class UpsertSyntaxTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function versions(): array
    {
        return [
            'MySQL 8.0.36' => ['8.0.36', true],
            'MySQL 8.0.19' => ['8.0.19', true],
            'MySQL 8.0.18' => ['8.0.18', false],
            'MySQL 8.4.2' => ['8.4.2', true],
            'MySQL 9.1.0' => ['9.1.0', true],
            'MySQL 5.7.44' => ['5.7.44-log', false],
            'MariaDB 10.11' => ['10.11.6-MariaDB', false],
            'MariaDB 11 prefix' => ['5.5.5-11.4.2-MariaDB-ubu2404', false],
            'unknown' => ['', false],
        ];
    }

    #[DataProvider('versions')]
    public function test_insert_alias_support(string $version, bool $expected): void
    {
        $this->assertSame($expected, QueryBuilder::mysql_supports_insert_alias($version));
    }
}
