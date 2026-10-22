<?php

namespace Tests\Portable;

use nsql\database\Config;
use nsql\database\exceptions\QueryException;
use nsql\database\Nsql;
use Tests\Support\PortableTestCase;

class QueryExecutionPortableTest extends PortableTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->create_table('p_items', ['name VARCHAR(50) NOT NULL']);
        $this->db->table('p_items')->insert_many([['name' => 'a'], ['name' => 'b'], ['name' => 'c']]);
    }

    /**
     * Cache açık yeni bağlantı (cache ayarı bağlantı kurulurken okunur).
     */
    private function cached_connection(): Nsql
    {
        $previous = Config::get('query_cache_enabled', false);
        Config::set('query_cache_enabled', true);
        try {
            return $this->connect();
        } finally {
            Config::set('query_cache_enabled', $previous);
        }
    }

    public function test_get_row_and_get_results_do_not_share_cache_entry(): void
    {
        $db = $this->cached_connection();
        $sql = 'SELECT name FROM p_items ORDER BY id LIMIT 2';

        // LIMIT içeren sorguya get_row() LIMIT 1 eklemez; iki metot aynı SQL'i çalıştırır
        $this->assertSame('a', $db->get_row($sql)?->name);
        $rows = $db->get_results($sql);
        $this->assertIsArray($rows);
        $this->assertSame(['a', 'b'], array_map(fn ($r) => $r->name, $rows));
        $this->assertSame('a', $db->get_row($sql)?->name);
        $this->assertCount(2, $db->get_results($sql));
        $this->assertGreaterThan(0, $db->get_cache_stats()['hits']);
    }

    public function test_preload_query_reloads_expired_entry(): void
    {
        $db = $this->cached_connection();
        $sql = 'SELECT name FROM p_items ORDER BY id';
        $this->assertTrue($db->preload_query($sql));

        // Kaydı süresi dolmuş gibi göster
        (function () {
            foreach ($this->query_cache as $key => $entry) {
                $this->query_cache[$key]['time'] = 0;
            }
        })->call($db);

        $this->assertTrue($db->preload_query($sql));
        $times = (fn () => array_column($this->query_cache, 'time'))->call($db);
        $this->assertNotContains(0, $times, 'Süresi dolmuş kayıt yeniden yüklenmeliydi');
        $this->assertCount(3, $db->get_results($sql));
    }

    public function test_connect_passes_extra_dsn_params_to_server(): void
    {
        if (self::driver() !== 'pgsql') {
            $this->markTestSkipped('application_name yalnızca PostgreSQL DSN parametresi');
        }

        // Kimlik bilgisi yalnızca DSN'de; argüman verilmez (önceden DB_USER/DB_PASS'e düşülüyordu)
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;application_name=nsql_dsn_probe;sslmode=disable;user=%s;password=%s',
            (string) Config::get('db_host', 'localhost'),
            (int) Config::get('db_port', 5432),
            (string) Config::get('db_name', 'nsql_test_db'),
            (string) Config::get('db_user', 'postgres'),
            (string) Config::get('db_pass', '')
        );
        $previous = Config::get('db_user');
        Config::set('db_user', 'nsql_wrong_user');
        try {
            $db = Nsql::connect($dsn);
        } finally {
            Config::set('db_user', $previous);
        }

        $this->assertSame('nsql_dsn_probe', $db->get_row('SHOW application_name')?->application_name);
    }

    public function test_enabled_query_analysis_blocks_critical_query_before_execution(): void
    {
        $this->db->enable_query_analysis();
        try {
            $this->db->statement('DELETE FROM p_items');
            $this->fail('QueryException bekleniyordu');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Kritik risk', $e->getMessage());
        } finally {
            $this->db->disable_query_analysis();
        }

        $this->assertSame(3, $this->db->table('p_items')->count());
        $this->assertSame(1, $this->db->statement('DELETE FROM p_items WHERE name = ?', ['a']));
    }

    /**
     * Analiz açıkken normal CRUD engellenmemeli (v2.4.1–2.5.0'da WHERE'li DELETE/UPDATE kritik sayılıyordu).
     */
    public function test_enabled_query_analysis_allows_normal_crud(): void
    {
        $this->db->enable_query_analysis();
        try {
            $this->db->table('p_items')->insert(['name' => 'd']);
            $this->assertSame(1, $this->db->table('p_items')->where('name', '=', 'd')->update(['name' => 'e']));
            $this->assertSame(1, $this->db->statement('UPDATE p_items SET name = ? WHERE name IN (?, ?)', ['f', 'e', 'yok']));
            $this->assertSame(4, $this->db->table('p_items')->where_not_in('name', [])->count());
            $this->assertSame(1, $this->db->table('p_items')->where('name', '=', 'f')->delete());
            $this->assertSame(1, $this->db->statement('DELETE FROM p_items WHERE name = ?;', ['a']));
        } finally {
            $this->db->disable_query_analysis();
        }

        $this->assertSame(['b', 'c'], $this->db->table('p_items')->order_by('id')->pluck('name'));
    }
}
