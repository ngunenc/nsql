<?php

namespace Tests\Unit;

use nsql\database\Nsql;
use PHPUnit\Framework\TestCase;

/**
 * Debug çıktısı için parametre yerleştirme (#100).
 */
class DebugInterpolationTest extends TestCase
{
    private Nsql $db;

    protected function setUp(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_debug_interp_' . getmypid() . '.sqlite';
        $this->db = new Nsql(db: $path, driver: 'sqlite', debug: true);
    }

    private function interpolate(string $query, array $params): string
    {
        return (fn () => $this->interpolate_query($query, $params))->call($this->db);
    }

    public function test_positional_value_containing_question_mark(): void
    {
        $this->assertSame(
            "SELECT * FROM t WHERE a = 'neden?' AND b = '2'",
            $this->interpolate('SELECT * FROM t WHERE a = ? AND b = ?', ['neden?', 2])
        );
    }

    public function test_named_value_containing_other_placeholder(): void
    {
        $this->assertSame(
            "SELECT ':b' AS a, '1' AS b, '3' AS b2",
            $this->interpolate('SELECT :a AS a, :b AS b, :b2 AS b2', ['a' => ':b', ':b' => 1, 'b2' => 3])
        );
    }

    public function test_structured_params_null_and_bool(): void
    {
        $this->assertSame(
            "UPDATE t SET a = NULL, b = '1' WHERE c = :unknown",
            $this->interpolate(
                'UPDATE t SET a = :__p0, b = :__p1 WHERE c = :unknown',
                [':__p0' => ['value' => null, 'type' => \PDO::PARAM_NULL], ':__p1' => ['value' => true, 'type' => \PDO::PARAM_BOOL]]
            )
        );
    }
}
