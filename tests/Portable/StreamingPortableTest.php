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
}
