<?php

namespace Tests\Unit;

use nsql\database\schema\ColumnInfo;
use nsql\database\schema\Schema;
use nsql\database\schema\SchemaDifference;
use nsql\database\schema\SchemaInspector;
use nsql\database\schema\SchemaReport;
use nsql\database\schema\SchemaValidator;
use nsql\database\schema\TableDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * #54: şema tanım API'si, tip eşleme ve varsayılan değer normalizasyonu.
 */
class SchemaDefinitionTest extends TestCase
{
    public function test_table_definition_collects_columns(): void
    {
        $schema = new Schema();
        $schema->table('users', function (TableDefinition $t) {
            $t->integer('id');
            $t->string('email', 255);
            $t->boolean('active')->default(true);
            $t->decimal('balance', 10, 2)->nullable();
        });

        $columns = $schema->tables()['users']->columns();
        $this->assertSame(['id', 'email', 'active', 'balance'], array_keys($columns));
        $this->assertSame(255, $columns['email']->length);
        $this->assertFalse($columns['email']->is_nullable());
        $this->assertFalse($columns['email']->has_default());
        $this->assertTrue($columns['active']->has_default());
        $this->assertTrue($columns['active']->get_default());
        $this->assertTrue($columns['balance']->is_nullable());
        $this->assertSame([10, 2], [$columns['balance']->precision, $columns['balance']->scale]);
    }

    /**
     * @return array<string, array{callable(Schema): void}>
     */
    public static function invalid_definitions(): array
    {
        return [
            'bad table name' => [fn (Schema $s) => $s->table('users; DROP', fn () => null)],
            'bad column name' => [fn (Schema $s) => $s->table('users', fn (TableDefinition $t) => $t->integer('a b'))],
            'unknown type' => [fn (Schema $s) => $s->table('users', fn (TableDefinition $t) => $t->column('a', 'money'))],
            'duplicate column' => [fn (Schema $s) => $s->table('users', function (TableDefinition $t) {
                $t->integer('id');
                $t->integer('ID');
            })],
            'duplicate table' => [function (Schema $s) {
                $s->table('users', fn () => null);
                $s->table('Users', fn () => null);
            }],
            'array default' => [fn (Schema $s) => $s->table('users', fn (TableDefinition $t) => $t->json('meta')->default([]))],
        ];
    }

    #[DataProvider('invalid_definitions')]
    public function test_invalid_definitions_are_rejected(callable $define): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $define(new Schema());
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function type_mappings(): array
    {
        return [
            'mysql int' => ['int', 'int(11)', 'mysql', 'integer'],
            'mysql bigint unsigned' => ['bigint', 'bigint(20) unsigned', 'mysql', 'integer'],
            'mysql tinyint(1)' => ['tinyint', 'tinyint(1)', 'mysql', 'boolean'],
            'mysql tinyint(4)' => ['tinyint', 'tinyint(4)', 'mysql', 'integer'],
            'mysql varchar' => ['varchar', 'varchar(255)', 'mysql', 'string'],
            'mysql longtext' => ['longtext', 'longtext', 'mysql', 'text'],
            'mysql timestamp' => ['timestamp', 'timestamp', 'mysql', 'datetime'],
            'mysql double' => ['double', 'double', 'mysql', 'float'],
            'pgsql varying' => ['character varying', 'varchar', 'pgsql', 'string'],
            'pgsql bool' => ['boolean', 'bool', 'pgsql', 'boolean'],
            'pgsql timestamptz' => ['timestamp with time zone', 'timestamptz', 'pgsql', 'datetime'],
            'pgsql jsonb' => ['jsonb', 'jsonb', 'pgsql', 'json'],
            'pgsql bytea' => ['bytea', 'bytea', 'pgsql', 'binary'],
            'pgsql smallint' => ['smallint', 'int2', 'pgsql', 'integer'],
            'sqlite tinyint(1) stays integer' => ['tinyint', 'tinyint(1)', 'sqlite', 'integer'],
            'unknown' => ['geometry', 'geometry', 'mysql', 'geometry'],
        ];
    }

    #[DataProvider('type_mappings')]
    public function test_type_family(string $data_type, string $column_type, string $driver, string $family): void
    {
        $this->assertSame($family, SchemaInspector::type_family($data_type, $column_type, $driver));
    }

    /**
     * @return array<string, array{string|null, string|null}>
     */
    public static function default_values(): array
    {
        return [
            'null' => [null, null],
            'NULL literal (MariaDB)' => ['NULL', null],
            'quoted (MariaDB/SQLite)' => ["'abc'", 'abc'],
            'escaped quote' => ["'it''s'", "it's"],
            'pgsql cast' => ["'abc'::character varying", 'abc'],
            'pgsql numeric cast' => ["'0'::numeric", '0'],
            'unquoted (MySQL 8)' => ['abc', 'abc'],
            'number' => ['0', '0'],
            'MariaDB current_timestamp()' => ['current_timestamp()', 'CURRENT_TIMESTAMP'],
            'pgsql now()' => ['now()', 'CURRENT_TIMESTAMP'],
            'parenthesized (SQLite)' => ['(CURRENT_TIMESTAMP)', 'CURRENT_TIMESTAMP'],
        ];
    }

    #[DataProvider('default_values')]
    public function test_normalize_default(?string $raw, ?string $normalized): void
    {
        $this->assertSame($normalized, SchemaInspector::normalize_default($raw));
    }

    /**
     * @return array<string, array{mixed, string|null, string, bool}>
     */
    public static function default_comparisons(): array
    {
        return [
            'bool true vs 1' => [true, '1', 'boolean', true],
            'bool true vs pgsql true' => [true, 'true', 'boolean', true],
            'bool false vs 1' => [false, '1', 'boolean', false],
            'int vs decimal string' => [0, '0.00', 'decimal', true],
            'string' => ['draft', 'draft', 'string', true],
            'string mismatch' => ['draft', 'published', 'string', false],
            'null vs null' => [null, null, 'string', true],
            'null vs value' => [null, '0', 'integer', false],
            'value vs null' => [0, null, 'integer', false],
            'now() vs CURRENT_TIMESTAMP' => ['now()', 'CURRENT_TIMESTAMP', 'datetime', true],
        ];
    }

    #[DataProvider('default_comparisons')]
    public function test_defaults_match(mixed $expected, ?string $actual, string $type, bool $match): void
    {
        $this->assertSame($match, SchemaValidator::defaults_match($expected, $actual, $type));
    }

    /**
     * @return array<string, array{string, string, string, bool}>
     */
    public static function type_compatibility(): array
    {
        return [
            'integer vs mysql tinyint(1)' => ['integer', 'tinyint(1)', 'boolean', true],
            'integer vs mysql tinyint(1) unsigned' => ['integer', 'tinyint(1) unsigned', 'boolean', true],
            'boolean vs mysql tinyint(1)' => ['boolean', 'tinyint(1)', 'boolean', true],
            'integer vs pgsql boolean' => ['integer', 'boolean', 'boolean', false],
            'string vs mysql tinyint(1)' => ['string', 'tinyint(1)', 'boolean', false],
            'boolean vs int' => ['boolean', 'int(11)', 'integer', false],
            'json vs mariadb longtext' => ['json', 'longtext', 'text', true],
        ];
    }

    #[DataProvider('type_compatibility')]
    public function test_type_compatibility(string $expected, string $raw_type, string $family, bool $compatible): void
    {
        $actual = new ColumnInfo('c', $raw_type, $family, false, null);
        $method = new \ReflectionMethod(SchemaValidator::class, 'type_compatible');

        $this->assertSame($compatible, $method->invoke(null, $expected, $actual));
    }

    public function test_report_formats_errors_and_warnings(): void
    {
        $report = new SchemaReport([
            new SchemaDifference(SchemaDifference::MISSING_COLUMN, 'users', 'email', 'string', null),
            new SchemaDifference(SchemaDifference::LENGTH_MISMATCH, 'users', 'name', 100, 255),
            new SchemaDifference(SchemaDifference::EXTRA_COLUMN, 'users', 'legacy', null, 'int', SchemaDifference::WARNING),
        ], ['users']);

        $this->assertFalse($report->is_valid());
        $this->assertCount(2, $report->errors());
        $this->assertCount(1, $report->warnings());
        $text = $report->format();
        $this->assertStringContainsString('1 tablo kontrol edildi: 2 hata, 1 uyarı', $text);
        $this->assertStringContainsString('[HATA] users.email: kolon yok', $text);
        $this->assertStringContainsString('[HATA] users.name: length beklenen 100, mevcut 255', $text);
        $this->assertStringContainsString('[UYARI] users.legacy', $text);
        $this->assertSame('length_mismatch', $report->to_array()['differences'][1]['kind']);

        $this->assertTrue((new SchemaReport([$report->warnings()[0]], ['users']))->is_valid());
    }

    public function test_schema_from_file_accepts_instance_or_callable(): void
    {
        $dir = sys_get_temp_dir() . '/nsql_schema_' . uniqid();
        mkdir($dir);
        file_put_contents(
            $dir . '/a.php',
            "<?php\n\$s = new \\nsql\\database\\schema\\Schema();\n\$s->table('a', fn (\$t) => \$t->integer('id'));\nreturn \$s;\n"
        );
        file_put_contents(
            $dir . '/b.php',
            "<?php\nreturn function (\\nsql\\database\\schema\\Schema \$s) { \$s->table('b', fn (\$t) => \$t->integer('id')); };\n"
        );
        file_put_contents($dir . '/c.php', "<?php\nreturn 42;\n");

        try {
            $this->assertSame(['a'], array_keys(Schema::from_file($dir . '/a.php')->tables()));
            $this->assertSame(['b'], array_keys(Schema::from_file($dir . '/b.php')->tables()));
            $this->expectException(\RuntimeException::class);
            Schema::from_file($dir . '/c.php');
        } finally {
            array_map('unlink', glob($dir . '/*.php') ?: []);
            rmdir($dir);
        }
    }
}
