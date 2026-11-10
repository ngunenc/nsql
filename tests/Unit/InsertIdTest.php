<?php

namespace Tests\Unit;

use nsql\database\drivers\InsertId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InsertIdTest extends TestCase
{
    /**
     * @return array<string, array{mixed, int|string}>
     */
    public static function values(): array
    {
        return [
            'int' => [5, 5],
            'numeric string' => ['42', 42],
            'zero' => ['0', 0],
            'false' => [false, 0],
            'null' => [null, 0],
            'empty' => ['', 0],
            'unsigned bigint overflow' => ['18446744073709551615', '18446744073709551615'],
            'signed bigint max' => [(string) PHP_INT_MAX, PHP_INT_MAX],
            'signed bigint overflow' => ['9223372036854775808', '9223372036854775808'],
            'negative' => ['-7', -7],
            'plus sign kept' => ['+7', '+7'],
            'uuid' => ['3f2504e0-4f89-11d3-9a0c-0305e82c3301', '3f2504e0-4f89-11d3-9a0c-0305e82c3301'],
            'leading zero kept' => ['007', '007'],
        ];
    }

    #[DataProvider('values')]
    public function test_normalize(mixed $input, int|string $expected): void
    {
        $this->assertSame($expected, InsertId::normalize($input));
    }

    public function test_out_of_range_id_raises_no_warning(): void
    {
        // PHP 8.5: int aralığı dışındaki sayısal string'in (int) dönüşümü uyarı üretir
        set_error_handler(static function (int $code, string $message): bool {
            throw new \ErrorException($message, 0, $code);
        });

        try {
            $this->assertSame('18446744073709551615', InsertId::normalize('18446744073709551615'));
        } finally {
            restore_error_handler();
        }
    }
}
