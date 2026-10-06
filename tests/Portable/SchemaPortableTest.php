<?php

namespace Tests\Portable;

use nsql\database\schema\Schema;
use nsql\database\schema\SchemaDifference;
use nsql\database\schema\SchemaInspector;
use nsql\database\schema\SchemaValidator;
use nsql\database\schema\TableDefinition;
use Tests\Support\PortableTestCase;

/**
 * #54: şema tanımı ile canlı tablo karşılaştırması (MySQL/MariaDB, PostgreSQL, SQLite).
 */
class SchemaPortableTest extends PortableTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db->query('DROP TABLE IF EXISTS p_schema_missing');
        $this->create_table('p_schema', [
            'email VARCHAR(100) NOT NULL',
            'nickname VARCHAR(50) NULL',
            "status VARCHAR(20) NOT NULL DEFAULT 'draft'",
            'score INT NOT NULL DEFAULT 0',
            'active BOOLEAN NOT NULL DEFAULT TRUE',
            'balance DECIMAL(10,2) NULL',
            'bio TEXT NULL',
            'created_at TIMESTAMP NULL',
        ]);
    }

    private static function expected(?callable $tweak = null): Schema
    {
        $schema = new Schema();
        $schema->table('p_schema', function (TableDefinition $t) use ($tweak) {
            $t->integer('id');
            $t->string('email', 100);
            $t->string('nickname', 50)->nullable();
            $t->string('status', 20)->default('draft');
            $t->integer('score')->default(0);
            $t->boolean('active')->default(true);
            $t->decimal('balance', 10, 2)->nullable();
            $t->text('bio')->nullable();
            $t->datetime('created_at')->nullable();
            if ($tweak !== null) {
                $tweak($t);
            }
        });

        return $schema;
    }

    public function test_matching_schema_is_valid(): void
    {
        $report = (new SchemaValidator($this->db))->validate(self::expected());

        $this->assertSame([], array_map(fn ($d) => $d->message(), $report->differences()));
        $this->assertTrue($report->is_valid());
        $this->assertSame(['p_schema'], $report->tables);
    }

    public function test_integer_definition_accepts_mysql_tinyint1(): void
    {
        $schema = new Schema();
        $schema->table('p_schema', fn (TableDefinition $t) => $t->integer('active')->default(1));
        $report = (new SchemaValidator($this->db))->validate($schema);
        $kinds = array_map(
            fn ($d) => $d->kind,
            array_values(array_filter($report->differences(), fn ($d) => $d->severity === SchemaDifference::ERROR))
        );

        if (self::driver() === 'mysql') {
            $this->assertSame([], $kinds);
        } else {
            $this->assertSame([SchemaDifference::TYPE_MISMATCH], $kinds);
        }
    }

    public function test_inspector_reports_normalized_columns(): void
    {
        $columns = (new SchemaInspector($this->db))->columns('p_schema');

        $this->assertNotNull($columns);
        $this->assertSame('string', $columns['email']->type);
        $this->assertSame(100, $columns['email']->length);
        $this->assertFalse($columns['email']->nullable);
        $this->assertTrue($columns['nickname']->nullable);
        $this->assertSame('draft', $columns['status']->default);
        $this->assertSame('boolean', $columns['active']->type);
        $this->assertSame([10, 2], [$columns['balance']->precision, $columns['balance']->scale]);
        $this->assertNull((new SchemaInspector($this->db))->columns('p_schema_missing'));
    }

    public function test_drift_is_reported(): void
    {
        $schema = new Schema();
        $schema->table('p_schema', function (TableDefinition $t) {
            $t->integer('id');
            $t->integer('email');
            $t->string('nickname', 80);
            $t->string('status', 20)->default('published');
            $t->integer('score')->default(0);
            $t->boolean('active')->default(true);
            $t->decimal('balance', 12, 4)->nullable();
            $t->text('bio')->nullable();
            $t->datetime('created_at')->nullable();
            $t->string('phone', 20)->nullable();
        });
        $schema->table('p_schema_missing', fn (TableDefinition $t) => $t->integer('id'));

        $report = (new SchemaValidator($this->db))->validate($schema);
        $kinds = [];
        foreach ($report->differences() as $d) {
            $kinds[$d->table . '.' . ($d->column ?? '*')][] = $d->kind;
        }

        $this->assertFalse($report->is_valid());
        $this->assertSame([SchemaDifference::TYPE_MISMATCH], $kinds['p_schema.email']);
        $this->assertSame([SchemaDifference::NULLABLE_MISMATCH, SchemaDifference::LENGTH_MISMATCH], $kinds['p_schema.nickname']);
        $this->assertSame([SchemaDifference::DEFAULT_MISMATCH], $kinds['p_schema.status']);
        $this->assertSame([SchemaDifference::PRECISION_MISMATCH], $kinds['p_schema.balance']);
        $this->assertSame([SchemaDifference::MISSING_COLUMN], $kinds['p_schema.phone']);
        $this->assertSame([SchemaDifference::MISSING_TABLE], $kinds['p_schema_missing.*']);
        $this->assertCount(7, $report->errors());
        $this->assertSame([], $report->warnings());
    }

    public function test_extra_columns_are_warnings_unless_strict(): void
    {
        $schema = new Schema();
        $schema->table('p_schema', function (TableDefinition $t) {
            $t->integer('id');
            $t->string('email', 100);
        });

        $lenient = (new SchemaValidator($this->db))->validate($schema);
        $this->assertTrue($lenient->is_valid());
        $this->assertCount(7, $lenient->warnings());
        $this->assertSame(SchemaDifference::EXTRA_COLUMN, $lenient->warnings()[0]->kind);

        $strict = (new SchemaValidator($this->db, strict: true))->validate($schema);
        $this->assertFalse($strict->is_valid());
        $this->assertCount(7, $strict->errors());
    }

    public function test_changes_after_alter_are_visible_with_query_cache(): void
    {
        $validator = new SchemaValidator($this->db);
        $with_phone = self::expected(fn (TableDefinition $t) => $t->string('phone', 20)->nullable());

        $this->assertFalse($validator->validate($with_phone)->is_valid());

        $this->db->query('ALTER TABLE p_schema ADD COLUMN phone VARCHAR(20) NULL');

        $this->assertTrue($validator->validate($with_phone)->is_valid());
    }
}
