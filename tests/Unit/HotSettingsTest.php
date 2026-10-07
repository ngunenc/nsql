<?php

namespace Tests\Unit;

use nsql\database\Config;
use nsql\database\traits\HotSettingsTrait;
use PHPUnit\Framework\TestCase;

class HotSettingsTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::set('slow_query_threshold_ms', null);
    }

    private function subject(): object
    {
        return new class () {
            use HotSettingsTrait;

            public function read(string $key, mixed $default = null): mixed
            {
                return $this->setting($key, $default);
            }
        };
    }

    public function test_value_is_refreshed_after_config_set(): void
    {
        Config::set('slow_query_threshold_ms', 50);
        $subject = $this->subject();
        $this->assertSame(50, $subject->read('slow_query_threshold_ms', 0));

        Config::set('slow_query_threshold_ms', 75);
        $this->assertSame(75, $subject->read('slow_query_threshold_ms', 0));
    }

    public function test_alias_and_case_resolve_to_same_value(): void
    {
        Config::set('SLOW_QUERY_THRESHOLD_MS', 20);

        $this->assertSame(20, $this->subject()->read('slow_query_threshold_ms', 0));
    }

    public function test_revision_changes_on_set_and_refresh(): void
    {
        $before = Config::revision();
        Config::set('slow_query_threshold_ms', 1);
        $after_set = Config::revision();
        Config::refresh();

        $this->assertGreaterThan($before, $after_set);
        $this->assertGreaterThan($after_set, Config::revision());
    }
}
