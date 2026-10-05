<?php

namespace Tests\Integration;

use nsql\database\config;
use PDO;
use Tests\Support\DatabaseTestCase;

/**
 * #45: unbuffered get_yield, keyset chunk_by_id, get_chunk temizliği, bellek eşikleri.
 */
class StreamingIntegrationTest extends DatabaseTestCase
{
    private const ROWS = 2500;

    protected function setUp(): void
    {
        parent::setUp();

        $values = [];
        for ($i = 1; $i <= self::ROWS; $i++) {
            $values[] = "('row{$i}')";
        }
        $this->db->query('INSERT INTO test_table (name) VALUES ' . implode(',', $values));
    }

    public function test_unbuffered_get_yield_streams_all_rows_in_order(): void
    {
        $ids = [];
        foreach ($this->db->get_yield('SELECT id, name FROM test_table ORDER BY id', [], true) as $row) {
            $ids[] = (int) $row->id;
        }

        $this->assertCount(self::ROWS, $ids);
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids);

        $this->assertSame(
            1,
            (int) $this->db->get_pdo()->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY),
            'Akış bitince buffered mod geri yüklenmeli'
        );
        $this->assertSame(self::ROWS, (int) $this->db->get_row('SELECT COUNT(*) AS c FROM test_table')->c);
    }

    public function test_unbuffered_get_yield_with_params_and_config_flag(): void
    {
        config::set('yield_unbuffered', true);
        try {
            $count = 0;
            foreach ($this->db->get_yield('SELECT id FROM test_table WHERE id > :min', ['min' => 2000]) as $row) {
                $count++;
            }
            $this->assertSame(500, $count);
        } finally {
            config::set('yield_unbuffered', false);
        }
    }

    public function test_query_during_unbuffered_stream_throws_clear_error(): void
    {
        try {
            foreach ($this->db->get_yield('SELECT id FROM test_table', [], true) as $row) {
                $this->db->get_row('SELECT 1 AS x');
            }
            $this->fail('Akış sırasında sorgu exception fırlatmalı');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('chunk_by_id', $e->getMessage());
        }

        $this->assertSame(1, (int) $this->db->get_row('SELECT 1 AS x')->x, 'Akış bırakılınca bağlantı kullanılabilir olmalı');
    }

    public function test_abandoned_stream_releases_connection(): void
    {
        foreach ($this->db->get_yield('SELECT id FROM test_table', [], true) as $row) {
            break;
        }

        $this->assertSame(self::ROWS, (int) $this->db->get_row('SELECT COUNT(*) AS c FROM test_table')->c);
    }

    public function test_chunk_by_id_returns_each_row_once(): void
    {
        $sizes = [];
        $ids = [];
        foreach ($this->db->chunk_by_id('SELECT id, name FROM test_table', [], 'id', 1000) as $chunk) {
            $sizes[] = count($chunk);
            foreach ($chunk as $row) {
                $ids[] = (int) $row->id;
            }
        }

        $this->assertSame([1000, 1000, 500], $sizes);
        $this->assertCount(self::ROWS, array_unique($ids));
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids);
    }

    public function test_chunk_by_id_with_named_and_positional_params(): void
    {
        $named = 0;
        foreach ($this->db->chunk_by_id('SELECT id FROM test_table WHERE id > :min', ['min' => 2000], 'id', 150) as $chunk) {
            $named += count($chunk);
        }
        $this->assertSame(500, $named);

        $positional = 0;
        foreach ($this->db->chunk_by_id('SELECT id FROM test_table WHERE id <= ?', [300], 'id', 100) as $chunk) {
            $positional += count($chunk);
        }
        $this->assertSame(300, $positional);
    }

    public function test_chunk_by_id_allows_writes_inside_loop(): void
    {
        foreach ($this->db->chunk_by_id('SELECT id FROM test_table', [], 'id', 1000) as $chunk) {
            $first = $chunk[0];
            $this->db->update('UPDATE test_table SET name = :n WHERE id = :id', ['n' => 'touched', 'id' => $first->id]);
        }

        $this->assertSame(3, (int) $this->db->get_row("SELECT COUNT(*) AS c FROM test_table WHERE name = 'touched'")->c);
    }

    public function test_chunk_by_id_validates_column(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        foreach ($this->db->chunk_by_id('SELECT id FROM test_table', [], 'id; DROP TABLE x') as $chunk) {
        }
    }

    public function test_chunk_by_id_missing_column_reports_error(): void
    {
        $chunks = iterator_to_array($this->db->chunk_by_id('SELECT name FROM test_table', [], 'name_missing', 10), false);

        $this->assertSame([], $chunks);
        $this->assertStringContainsString('name_missing', (string) $this->db->get_last_error());
    }

    public function test_limit_inside_subquery_is_allowed(): void
    {
        $sql = 'SELECT id FROM test_table WHERE id IN (SELECT id FROM (SELECT id FROM test_table ORDER BY id LIMIT 5) t)';

        $rows = iterator_to_array($this->db->get_yield($sql), false);
        $this->assertCount(5, $rows);

        $chunks = iterator_to_array($this->db->get_chunk("SELECT id, 'LIMIT' AS word FROM test_table", [], 1000), false);
        $this->assertCount(3, $chunks);
    }

    public function test_top_level_limit_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        iterator_to_array($this->db->get_yield('SELECT id FROM test_table LIMIT 10'));
    }

    public function test_get_chunk_does_not_fill_query_cache(): void
    {
        $before = $this->db->get_cache_stats()['size'];
        foreach ($this->db->get_chunk('SELECT id FROM test_table', [], 100) as $chunk) {
        }
        $this->assertSame($before, $this->db->get_cache_stats()['size']);
    }

    public function test_memory_thresholds_follow_memory_limit_ratio(): void
    {
        $original = ini_get('memory_limit');
        ini_set('memory_limit', '1G');
        try {
            $thresholds = (fn () => $this->memory_thresholds())->call($this->db);
            if (config::has('memory_limit_warning') || config::has('memory_limit_critical')) {
                $this->markTestSkipped('.env mutlak bellek eşiği tanımlıyor');
            }

            $gb = 1024 * 1024 * 1024;
            $this->assertSame((int) ($gb * 0.75), $thresholds['warning']);
            $this->assertSame((int) ($gb * 0.9), $thresholds['critical']);

            config::set('memory_critical_ratio', 0.5);
            $this->assertSame((int) ($gb * 0.5), (fn () => $this->memory_thresholds())->call($this->db)['critical']);

            config::set('memory_limit_critical', 123456789);
            $this->assertSame(123456789, (fn () => $this->memory_thresholds())->call($this->db)['critical']);
        } finally {
            ini_set('memory_limit', (string) $original);
            config::refresh();
            config::set('query_cache_enabled', self::query_cache_suite());
        }
    }
}
