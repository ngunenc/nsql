<?php

namespace Tests\Portable;

use nsql\database\Config;
use Tests\Support\PortableTestCase;

class StreamingPortableTest extends PortableTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->create_table('p_events', ['label VARCHAR(20) NOT NULL']);

        $rows = [];
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = ['label' => 'e' . $i];
        }
        $this->db->batch_insert('p_events', $rows);
    }

    public function test_get_yield_streams_all_rows(): void
    {
        $labels = [];
        foreach ($this->db->get_yield('SELECT label FROM p_events WHERE id > ? ORDER BY id', [20]) as $row) {
            $labels[] = $row->label;
        }

        $this->assertSame(['e21', 'e22', 'e23', 'e24', 'e25'], $labels);
    }

    public function test_get_yield_buffered_allows_nested_queries(): void
    {
        $count = 0;
        foreach ($this->db->get_yield('SELECT id FROM p_events WHERE id <= 3 ORDER BY id', [], unbuffered: false) as $row) {
            $count += (int) $this->db->get_row('SELECT COUNT(*) AS c FROM p_events WHERE id <= ?', [$row->id])->c;
        }

        $this->assertSame(1 + 2 + 3, $count);
    }

    public function test_get_yield_buffered_tolerates_zero_intervals(): void
    {
        Config::set('generator_cleanup_interval', 0);
        Config::set('generator_gc_interval_multiplier', 0);

        try {
            $count = 0;
            foreach ($this->db->get_yield('SELECT id FROM p_events ORDER BY id', [], unbuffered: false) as $row) {
                $count++;
            }
        } finally {
            Config::set('generator_cleanup_interval', null);
            Config::set('generator_gc_interval_multiplier', null);
        }

        $this->assertSame(25, $count);
    }

    /**
     * Sondaki `;` veya satır yorumu eklenen LIMIT/OFFSET'i etkisiz bırakıyor, aynı satırlar
     * MAX_RESULT_SET_SIZE'a kadar tekrar tekrar dönüyordu.
     *
     * @return array<string, array{string}>
     */
    public static function terminated_queries(): array
    {
        return [
            'semicolon' => ['SELECT id FROM p_events ORDER BY id;'],
            'semicolon and spaces' => ["SELECT id FROM p_events ORDER BY id ;  \n"],
            'line comment' => ['SELECT id FROM p_events ORDER BY id -- son yorum'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('terminated_queries')]
    public function test_buffered_paging_is_not_broken_by_query_terminators(string $query): void
    {
        Config::set('default_chunk_size', 10);
        Config::set('auto_adjust_chunk_size', false);
        try {
            $ids = [];
            foreach ($this->db->get_yield($query, [], unbuffered: false) as $row) {
                $ids[] = (int) $row->id;
                if (count($ids) > 30) {
                    break;
                }
            }

            $chunks = [];
            foreach ($this->db->get_chunk($query, [], 10) as $chunk) {
                $chunks[] = count($chunk);
                if (count($chunks) > 3) {
                    break;
                }
            }
        } finally {
            Config::set('default_chunk_size', null);
            Config::set('auto_adjust_chunk_size', null);
        }

        $this->assertSame(range(1, 25), $ids);
        $this->assertSame([10, 10, 5], $chunks);
    }

    public function test_chunk_by_id_visits_every_row(): void
    {
        $seen = [];
        foreach ($this->db->chunk_by_id('SELECT id, label FROM p_events', [], 'id', 10) as $chunk) {
            foreach ($chunk as $row) {
                $seen[] = (int) $row->id;
            }
        }

        $this->assertSame(range(1, 25), $seen);
    }

    public function test_chunk_by_id_with_trailing_line_comment(): void
    {
        $sizes = [];
        foreach ($this->db->chunk_by_id("SELECT id, label FROM p_events WHERE id > ? -- son yorum", [5], 'id', 10) as $chunk) {
            $sizes[] = count($chunk);
        }

        $this->assertSame([10, 10], $sizes);
    }

    /**
     * Anahtar NULL ise imleç ilerlemez: aynı parça sonsuza dek dönüyordu.
     */
    public function test_chunk_by_id_rejects_null_key_instead_of_looping(): void
    {
        $this->create_table('p_null_keys', ['sort_key INT NULL']);
        $this->db->batch_insert('p_null_keys', array_fill(0, 4, ['sort_key' => null]));

        $chunks = 0;

        try {
            foreach ($this->db->chunk_by_id('SELECT id, sort_key FROM p_null_keys', [], 'sort_key', 2) as $chunk) {
                if (++$chunks > 5) {
                    $this->fail('chunk_by_id() NULL anahtarda aynı parçayı tekrar tekrar döndürüyor');
                }
            }
            $this->fail('InvalidArgumentException bekleniyordu');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('NULL', $e->getMessage());
        }

        $this->assertLessThanOrEqual(1, $chunks);
    }

    public function test_chunk_by_id_rejects_column_with_trailing_newline(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('keyset');
        foreach ($this->db->chunk_by_id('SELECT id FROM p_events', [], "id\n", 10) as $chunk) {
            $this->fail('Geçersiz kolon adıyla sorgu çalışmamalı');
        }
    }

    public function test_chunk_by_id_allows_null_key_on_last_partial_chunk(): void
    {
        $this->create_table('p_null_keys', ['sort_key INT NULL']);
        $this->db->batch_insert('p_null_keys', array_fill(0, 2, ['sort_key' => null]));

        $rows = 0;
        foreach ($this->db->chunk_by_id('SELECT id, sort_key FROM p_null_keys', [], 'sort_key', 5) as $chunk) {
            $rows += count($chunk);
        }

        $this->assertSame(2, $rows);
    }
}
