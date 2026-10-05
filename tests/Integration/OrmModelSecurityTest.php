<?php

namespace Tests\Integration;

use nsql\database\exceptions\QueryException;
use nsql\database\orm\Model;
use Tests\Support\DatabaseTestCase;

class HiddenNameModel extends Model
{
    protected string $table = 'test_table';
    protected array $fillable = ['name'];
    protected array $hidden = ['name'];
    protected bool $timestamps = false;
}

class NoFillableModel extends Model
{
    protected string $table = 'test_table';
    protected bool $timestamps = false;
}

class BadTableModel extends Model
{
    protected string $table = 'test_table; DROP TABLE test_table';
    protected array $fillable = ['name'];
    protected bool $timestamps = false;
}

/**
 * #32: mass assignment, ham kolon/tablo adları ve hidden alanların kaydı.
 */
class OrmModelSecurityTest extends DatabaseTestCase
{
    public function test_constructor_ignores_non_fillable_attributes(): void
    {
        $model = new TestTableModel($this->db, ['name' => 'a', 'id' => 99, 'is_admin' => 1]);

        $this->assertSame(['name' => 'a'], $model->to_array());
        $this->assertNull($model->id);
    }

    public function test_empty_fillable_allows_no_mass_assignment(): void
    {
        $model = new NoFillableModel($this->db, ['name' => 'a']);
        $model->name = 'b';
        $model->fill(['name' => 'c']);

        $this->assertSame([], $model->to_array());

        $model->force_fill(['name' => 'trusted']);
        $this->assertSame('trusted', $model->name);
    }

    public function test_invalid_column_name_throws_on_save(): void
    {
        $model = new TestTableModel($this->db);
        $model->force_fill(['name' => 'x', 'name) VALUES (1); DROP TABLE test_table; --' => 'y']);

        $this->expectException(\InvalidArgumentException::class);
        $model->save();
    }

    public function test_invalid_table_name_throws_on_save_and_delete(): void
    {
        $model = new BadTableModel($this->db, ['name' => 'x']);

        try {
            $model->save();
            $this->fail('Geçersiz tablo adı exception fırlatmalı');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Geçersiz tanımlayıcı', $e->getMessage());
        }

        $model->set_attribute('id', 1);
        $this->expectException(\InvalidArgumentException::class);
        $model->delete();
    }

    public function test_hidden_attribute_is_saved_but_not_serialized(): void
    {
        $model = new HiddenNameModel($this->db, ['name' => 'secret']);

        $this->assertTrue($model->save());
        $id = $model->id;
        $this->assertNotEmpty($id);

        $row = $this->db->get_row('SELECT name FROM test_table WHERE id = :id', ['id' => $id]);
        $this->assertSame('secret', $row->name);

        $this->assertArrayNotHasKey('name', $model->to_array());
        $this->assertStringNotContainsString('secret', $model->to_json());
    }

    public function test_save_update_and_delete_return_bool(): void
    {
        $model = new TestTableModel($this->db, ['name' => 'first']);
        $this->assertTrue($model->save());
        $id = $model->id;

        $model->name = 'second';
        $this->assertTrue($model->save());
        $this->assertSame('second', $this->db->get_row('SELECT name FROM test_table WHERE id = :id', ['id' => $id])->name);

        $this->assertTrue($model->delete());
        $this->assertNull($this->db->get_row('SELECT id FROM test_table WHERE id = :id', ['id' => $id]));
    }

    public function test_batch_insert_rejects_invalid_column_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->db->batch_insert('test_table', [['name`) VALUES (1); --' => 'x']]);
    }

    public function test_batch_update_rejects_invalid_column_name(): void
    {
        $this->expectException(QueryException::class);
        $this->db->batch_update('test_table', [['id' => 1, 'name` = 1 --' => 'x']]);
    }
}
