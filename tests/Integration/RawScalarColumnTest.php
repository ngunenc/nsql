<?php

namespace Tests\Integration;

use InvalidArgumentException;
use nsql\database\Config;
use nsql\database\Nsql;
use Tests\Support\DatabaseTestCase;

/**
 * get_var(), get_col(), get_row()/get_results() çıktı biçimleri (ARRAY_A/ARRAY_N) ve rows_affected().
 */
class RawScalarColumnTest extends DatabaseTestCase
{
    /** @return array<string, int> name => id */
    private function seed(): array
    {
        $ids = [];
        foreach (['alpha', 'beta', 'gamma'] as $name) {
            $ids[$name] = $this->db->insert('INSERT INTO test_table (name) VALUES (:name)', ['name' => $name]);
        }

        return $ids;
    }

    public function test_get_var_returns_scalar(): void
    {
        $this->seed();

        $this->assertSame(3, (int) $this->db->get_var('SELECT COUNT(*) FROM test_table'));
    }

    public function test_get_var_with_params(): void
    {
        $ids = $this->seed();

        $this->assertSame('beta', $this->db->get_var('SELECT name FROM test_table WHERE id = :id', ['id' => $ids['beta']]));
    }

    public function test_get_var_null_value_and_missing_row_both_return_null(): void
    {
        $ids = $this->seed();

        $this->assertNull($this->db->get_var('SELECT value FROM test_table WHERE id = :id', ['id' => $ids['alpha']]));
        $this->assertNull($this->db->get_var('SELECT name FROM test_table WHERE id = :id', ['id' => 999999]));
    }

    public function test_get_var_keeps_existing_limit_and_lock_clauses(): void
    {
        $this->seed();

        $this->assertSame('alpha', $this->db->get_var('SELECT name FROM test_table ORDER BY id LIMIT 1'));

        $this->db->begin();
        $this->assertSame('gamma', $this->db->get_var('SELECT name FROM test_table ORDER BY id DESC FOR UPDATE'));
        $this->db->commit();
    }

    public function test_get_col_default_column(): void
    {
        $this->seed();

        $this->assertSame(['alpha', 'beta', 'gamma'], $this->db->get_col('SELECT name FROM test_table ORDER BY id'));
    }

    public function test_get_col_with_column_index(): void
    {
        $this->seed();

        $this->assertSame(
            ['alpha', 'beta', 'gamma'],
            $this->db->get_col('SELECT id, name FROM test_table ORDER BY id', [], 1)
        );
    }

    public function test_get_col_empty_result_and_negative_index(): void
    {
        $this->assertSame([], $this->db->get_col('SELECT name FROM test_table'));

        $this->expectException(InvalidArgumentException::class);
        $this->db->get_col('SELECT name FROM test_table', [], -1);
    }

    public function test_get_row_output_formats(): void
    {
        $ids = $this->seed();
        $sql = 'SELECT id, name FROM test_table WHERE id = :id';

        $object = $this->db->get_row($sql, ['id' => $ids['beta']]);
        $this->assertIsObject($object);
        $this->assertSame('beta', $object->name);

        $assoc = $this->db->get_row($sql, ['id' => $ids['beta']], Nsql::ARRAY_A);
        $this->assertIsArray($assoc);
        $this->assertSame('beta', $assoc['name']);

        $numeric = $this->db->get_row($sql, ['id' => $ids['beta']], Nsql::ARRAY_N);
        $this->assertIsArray($numeric);
        $this->assertSame('beta', $numeric[1]);
    }

    public function test_get_results_output_formats(): void
    {
        $this->seed();
        $sql = 'SELECT name FROM test_table ORDER BY id';

        $assoc = $this->db->get_results($sql, [], Nsql::ARRAY_A);
        $this->assertSame(['alpha', 'beta', 'gamma'], array_column($assoc, 'name'));

        $numeric = $this->db->get_results($sql, [], Nsql::ARRAY_N);
        $this->assertSame('gamma', $numeric[2][0]);

        $this->assertIsObject($this->db->get_results($sql)[0]);
    }

    public function test_legacy_pdo_fetch_constant_is_ignored_and_returns_objects(): void
    {
        $this->seed();

        // Önceki sürümlerde get_results($sql, $params, PDO::FETCH_ASSOC) üçüncü parametreyi yok sayıp nesne döndürürdü
        $rows = $this->db->get_results('SELECT name FROM test_table ORDER BY id', [], \PDO::FETCH_ASSOC);

        $this->assertIsObject($rows[0]);
        $this->assertSame('alpha', $rows[0]->name);
    }

    public function test_invalid_output_format_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->get_row('SELECT 1', [], 'XML');
    }

    public function test_query_cache_does_not_mix_output_formats(): void
    {
        $ids = $this->seed();

        Config::set('query_cache_enabled', true);
        try {
            $cached = new Nsql(
                host: Config::get('db_host', 'localhost'),
                db: Config::get('db_name', 'nsql_test_db'),
                user: Config::get('db_user', 'root'),
                pass: Config::get('db_pass', '')
            );

            $row_sql = 'SELECT name FROM test_table WHERE id = :id';
            $params = ['id' => $ids['alpha']];
            $results_sql = 'SELECT name FROM test_table ORDER BY id';

            // Her biçim iki kez: ilk çağrı önbelleğe yazar, ikincisi önbellekten okur
            for ($i = 0; $i < 2; $i++) {
                $this->assertIsObject($cached->get_row($row_sql, $params));
                $this->assertSame('alpha', $cached->get_row($row_sql, $params, Nsql::ARRAY_A)['name']);
                $this->assertIsArray($cached->get_row($row_sql, $params, Nsql::ARRAY_N));

                $this->assertIsObject($cached->get_results($results_sql)[0]);
                $this->assertIsArray($cached->get_results($results_sql, [], Nsql::ARRAY_A)[0]);

                $this->assertSame('alpha', $cached->get_var($row_sql, $params));
                $this->assertSame(['alpha', 'beta', 'gamma'], $cached->get_col($results_sql));
            }
        } finally {
            Config::set('query_cache_enabled', self::query_cache_suite());
        }
    }

    public function test_rows_affected_after_write_operations(): void
    {
        $this->seed();

        $this->db->update('UPDATE test_table SET value = :v', ['v' => 'x']);
        $this->assertSame(3, $this->db->rows_affected());

        $this->db->delete('DELETE FROM test_table WHERE name = :n', ['n' => 'alpha']);
        $this->assertSame(1, $this->db->rows_affected());

        $this->db->statement('UPDATE test_table SET value = NULL');
        $this->assertSame(2, $this->db->rows_affected());

        $this->db->insert('INSERT INTO test_table (name) VALUES (:name)', ['name' => 'delta']);
        $this->assertSame(1, $this->db->rows_affected());

        $this->assertSame(2, $this->db->batch_insert('test_table', [['name' => 'e'], ['name' => 'f']]));
        $this->assertSame(2, $this->db->rows_affected());
    }

    public function test_rows_affected_is_not_changed_by_select(): void
    {
        $this->seed();
        // MySQL rowCount() değişen satırları sayar: value zaten NULL olduğundan değer değiştiriliyor
        $this->db->statement("UPDATE test_table SET value = 'x'");
        $this->assertSame(3, $this->db->rows_affected());

        $this->db->get_results('SELECT * FROM test_table');
        $this->db->get_var('SELECT COUNT(*) FROM test_table');

        $this->assertSame(3, $this->db->rows_affected());
    }

    public function test_rows_affected_after_raw_query_write(): void
    {
        $this->seed();
        $this->db->insert('INSERT INTO test_table (name) VALUES (:name)', ['name' => 'delta']);
        $this->assertSame(1, $this->db->rows_affected());

        // ezSQL'den gelen kod yazmaları query() ile yapar
        $this->db->query('DELETE FROM test_table');

        $this->assertSame(4, $this->db->rows_affected());
    }

    public function test_get_col_out_of_range_index_throws_and_connection_stays_usable(): void
    {
        $this->seed();

        try {
            $this->db->get_col('SELECT name FROM test_table', [], 5);
            $this->fail('InvalidArgumentException bekleniyordu');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('5', $e->getMessage());
        }

        $this->assertSame(3, (int) $this->db->get_var('SELECT COUNT(*) FROM test_table'));
    }

    public function test_invalid_output_format_message_does_not_echo_unsafe_input(): void
    {
        try {
            $this->db->get_row('SELECT 1', [], "x\n<script>");
            $this->fail('InvalidArgumentException bekleniyordu');
        } catch (InvalidArgumentException $e) {
            $this->assertStringNotContainsString('<script>', $e->getMessage());
            $this->assertStringNotContainsString("\n", $e->getMessage());
        }
    }

    /**
     * @return array<string, array{callable(Nsql): mixed}>
     */
    public static function unnamed_result_calls(): array
    {
        $sql = 'SELECT name, value AS password FROM test_table WHERE name = :n';

        return [
            'get_var' => [fn (Nsql $db) => $db->get_var('SELECT value AS password FROM test_table WHERE name = :n', ['n' => 'ali'])],
            'get_col' => [fn (Nsql $db) => $db->get_col('SELECT name, value AS password FROM test_table', [], 1)],
            'get_row ARRAY_N' => [fn (Nsql $db) => $db->get_row($sql, ['n' => 'ali'], Nsql::ARRAY_N)],
            'get_results ARRAY_N' => [fn (Nsql $db) => $db->get_results($sql, ['n' => 'ali'], Nsql::ARRAY_N)],
        ];
    }

    /**
     * Debug çıktısı hassas alanları sütun adına göre maskeler; adsız sonuçlar bu korumayı atlamamalı.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unnamed_result_calls')]
    public function test_debug_output_masks_sensitive_columns_of_unnamed_results(callable $call): void
    {
        $this->db->insert(
            'INSERT INTO test_table (name, value) VALUES (:name, :value)',
            ['name' => 'ali', 'value' => 'GIZLI_SIFRE_123']
        );

        $this->with_debug_db(function (Nsql $db, string $log_path) use ($call): void {
            $result = $call($db);
            // Dönen değer maskelenmez; yalnızca debug çıktısı
            $this->assertStringContainsString('GIZLI_SIFRE_123', (string) json_encode($result));

            $html = $this->capture_debug($db);

            $this->assertStringNotContainsString('GIZLI_SIFRE_123', $html);
            $this->assertStringNotContainsString('GIZLI_SIFRE_123', (string) @file_get_contents($log_path));
        });
    }

    public function test_debug_output_keeps_non_sensitive_columns_visible(): void
    {
        $this->db->insert('INSERT INTO test_table (name) VALUES (:name)', ['name' => 'gorunur_ad']);

        $this->with_debug_db(function (Nsql $db): void {
            $db->get_var('SELECT name FROM test_table');

            $this->assertStringContainsString('gorunur_ad', $this->capture_debug($db));
        });
    }

    /**
     * Debug açık bağlantı; log geçici dizine yazılır ve sonra silinir.
     *
     * @param callable(Nsql, string): void $test
     */
    private function with_debug_db(callable $test): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_rawdebug_' . uniqid('', true);
        Config::set('log_dir', $dir);
        Config::set('log_file', 'debug.txt');
        $db = null;
        try {
            $db = new Nsql(
                host: Config::get('db_host', 'localhost'),
                db: Config::get('db_name', 'nsql_test_db'),
                user: Config::get('db_user', 'root'),
                pass: Config::get('db_pass', ''),
                debug: true
            );
            $test($db, $dir . DIRECTORY_SEPARATOR . 'debug.txt');
        } finally {
            if ($db !== null) {
                (fn () => $this->disconnect())->call($db);
            }
            Config::set('log_dir', null);
            Config::set('log_file', null);
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    private function capture_debug(Nsql $db): string
    {
        ob_start();
        $db->debug();

        return (string) ob_get_clean();
    }

    public function test_rows_affected_is_zero_after_failed_write(): void
    {
        $this->seed();
        $this->db->statement("UPDATE test_table SET value = 'x'");
        $this->assertSame(3, $this->db->rows_affected());

        $this->db->set_throw_on_error(false);
        $result = $this->db->update('UPDATE tablo_yok SET x = 1');

        $this->assertFalse($result);
        $this->assertSame(0, $this->db->rows_affected());
    }
}
