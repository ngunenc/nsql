<?php

namespace Tests\Integration;

use nsql\database\orm\model;
use nsql\database\query_builder;
use Tests\Support\DatabaseTestCase;

class TestTableModel extends model
{
    protected string $table = 'test_table';
    protected array $fillable = ['name'];
    protected bool $timestamps = false;
}

class OrmSmokeTest extends DatabaseTestCase
{
    public function test_model_exposes_query_builder_for_table(): void
    {
        $model = new TestTableModel($this->db);
        $builder = $model->query();
        $this->assertInstanceOf(query_builder::class, $builder);
        $sql = $builder->select('*')->where('name', '=', 'x')->get_query();
        $this->assertStringContainsString('test_table', $sql);
    }

    public function test_model_fillable_attributes(): void
    {
        $model = new TestTableModel($this->db);
        $model->name = 'filled';
        $model->ignored = 'nope';
        $this->assertSame('filled', $model->name);
        $this->assertNull($model->ignored);
        $this->assertSame(['name' => 'filled'], $model->to_array());
    }

    public function test_model_all_returns_array(): void
    {
        $this->db->insert(
            "INSERT INTO test_table (name) VALUES (:name)",
            ['name' => 'ORM list']
        );

        $rows = TestTableModel::all($this->db);
        $this->assertIsArray($rows);
    }
}
