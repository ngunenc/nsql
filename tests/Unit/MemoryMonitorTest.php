<?php

namespace Tests\Unit;

use nsql\database\optimization\memory_monitor;
use PHPUnit\Framework\TestCase;

class MemoryMonitorTest extends TestCase
{
    public function test_parse_limit_handles_units(): void
    {
        $this->assertSame(512, memory_monitor::parse_limit('512'));
        $this->assertSame(64 * 1024, memory_monitor::parse_limit('64K'));
        $this->assertSame(128 * 1024 * 1024, memory_monitor::parse_limit('128m'));
        $this->assertSame(2 * 1024 * 1024 * 1024, memory_monitor::parse_limit(' 2G '));
    }

    public function test_format_bytes(): void
    {
        $this->assertSame('0 B', memory_monitor::format_bytes(0));
        $this->assertSame('1.5 KB', memory_monitor::format_bytes(1536));
        $this->assertSame('3 MB', memory_monitor::format_bytes(3 * 1024 * 1024));
    }

    public function test_thresholds_follow_memory_limit(): void
    {
        $previous = ini_get('memory_limit');
        ini_set('memory_limit', '1G');

        try {
            $thresholds = memory_monitor::thresholds();
            $gb = 1024 ** 3;
            $this->assertSame((int) ($gb * 0.75), $thresholds['warning']);
            $this->assertSame((int) ($gb * 0.9), $thresholds['critical']);
            $this->assertSame($gb, memory_monitor::memory_limit());
        } finally {
            ini_set('memory_limit', (string) $previous);
        }
    }

    public function test_chunk_size_is_at_least_one(): void
    {
        $previous = memory_monitor::chunk_size();

        try {
            memory_monitor::set_chunk_size(0);
            $this->assertSame(1, memory_monitor::chunk_size());
            memory_monitor::set_chunk_size(250);
            $this->assertSame(250, memory_monitor::stats()['current_chunk_size']);
        } finally {
            memory_monitor::set_chunk_size($previous);
        }
    }

    public function test_check_below_thresholds_does_not_cleanup(): void
    {
        $called = false;
        memory_monitor::check(function () use (&$called): void {
            $called = true;
        });

        $this->assertFalse($called);
    }
}
