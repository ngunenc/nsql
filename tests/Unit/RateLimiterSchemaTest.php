<?php

namespace Tests\Unit;

use nsql\security\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RateLimiterSchemaTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function invalid_tables(): array
    {
        return [
            'stacked ddl' => ['rate_limits (id INT); DROP TABLE users; --'],
            'quoted' => ['`rate_limits`'],
            'leading digit' => ['1rate'],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalid_tables')]
    public function test_schema_statements_reject_invalid_table(string $table): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RateLimiter::schema_statements($table, 'sqlite');
    }

    public function test_schema_sql_rejects_invalid_table(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RateLimiter::schema_sql('x; DROP TABLE users');
    }

    public function test_valid_table_produces_ddl(): void
    {
        $statements = RateLimiter::schema_statements('custom_limits', 'sqlite');

        $this->assertNotEmpty($statements);
        $this->assertStringContainsString('custom_limits', $statements[0]);
    }
}
