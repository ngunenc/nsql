<?php

namespace Tests\Unit;

use InvalidArgumentException;
use nsql\database\exceptions\ErrorCodes;
use nsql\database\validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ValidatorTest extends TestCase
{
    /**
     * @return array<string, array{mixed, array<string, mixed>, bool}>
     */
    public static function cases(): array
    {
        return [
            'required ok' => ['x', ['required' => true], true],
            'required empty' => ['', ['required' => true], false],
            'required null' => [null, ['required' => true], false],
            'type int' => [5, ['type' => 'int'], true],
            'type int string' => ['5', ['type' => 'int'], false],
            'type unknown' => [5, ['type' => 'decimal'], false],
            'min/max' => [5, ['min' => 1, 'max' => 10], true],
            'below min' => [0, ['min' => 1], false],
            'above max' => [11, ['max' => 10], false],
            'min non numeric' => ['abc', ['min' => 1], false],
            'length utf8' => ['şğü', ['min_length' => 3, 'max_length' => 3], true],
            'too long' => ['abcd', ['max_length' => 3], false],
            'array length' => [[1, 2], ['min_length' => 2, 'max_length' => 2], true],
            'length non string' => [5, ['min_length' => 1], false],
            'pattern' => ['AB12', ['pattern' => '/^[A-Z]{2}\d{2}$/'], true],
            'pattern fail' => ['ab12', ['pattern' => '/^[A-Z]{2}\d{2}$/'], false],
            'in' => ['a', ['in' => ['a', 'b']], true],
            'in strict' => ['1', ['in' => [1, 2]], false],
            'not_in' => ['c', ['not_in' => ['a', 'b']], true],
            'email' => ['a@example.com', ['email' => true], true],
            'email fail' => ['not-mail', ['email' => true], false],
            'url' => ['https://example.com/x', ['url' => true], true],
            'url fail' => ['example', ['url' => true], false],
            'numeric' => ['1.5', ['numeric' => true], true],
            'integer string' => ['42', ['integer' => true], true],
            'integer negative string' => ['-1', ['integer' => true], false],
            'float' => ['1.5', ['float' => true], true],
            'float int' => [1, ['float' => true], false],
            'boolean' => ['yes', ['boolean' => true], true],
            'boolean fail' => ['maybe', ['boolean' => true], false],
            'array' => [[], ['array' => true], true],
            'string' => ['s', ['string' => true], true],
            'custom callable' => [4, ['even' => fn ($v) => $v % 2 === 0], true],
            'custom callable fail' => [3, ['even' => fn ($v) => $v % 2 === 0], false],
            'unknown non-callable rule passes' => [3, ['something' => 'x'], true],
        ];
    }

    /**
     * @param array<string, mixed> $rules
     */
    #[DataProvider('cases')]
    public function test_rules(mixed $value, array $rules, bool $expected): void
    {
        if (! $expected) {
            $this->expectException(InvalidArgumentException::class);
        }

        $this->assertTrue(Validator::validate($value, $rules));
    }

    public function test_validate_many_collects_errors_per_field(): void
    {
        $errors = Validator::validate_many(
            ['name' => 'Ali', 'age' => 'x'],
            ['name' => ['required' => true], 'age' => ['numeric' => true], 'email' => ['required' => true]]
        );

        $this->assertSame(['age', 'email'], array_keys($errors));
        $this->assertStringContainsString('numeric', $errors['age']);
    }

    public function test_sql_identifier_and_param(): void
    {
        $this->assertTrue(Validator::validate_sql_identifier('users_2'));
        $this->assertFalse(Validator::validate_sql_identifier('2users'));
        $this->assertFalse(Validator::validate_sql_identifier('users; DROP'));
        $this->assertFalse(Validator::validate_sql_identifier(str_repeat('a', 65)));

        $this->assertTrue(Validator::validate_sql_param('x'));
        $this->assertTrue(Validator::validate_sql_param(null));
        $this->assertFalse(Validator::validate_sql_param(['x']));
        $this->assertFalse(Validator::validate_sql_param(new \stdClass()));
    }

    public function test_error_codes_messages_and_categories(): void
    {
        $this->assertSame('Veritabanı bağlantısı başarısız', ErrorCodes::get_message(ErrorCodes::CONNECTION_FAILED));
        $this->assertSame('Bilinmeyen hata kodu: 42', ErrorCodes::get_message(42));
        $this->assertSame('query', ErrorCodes::get_category(ErrorCodes::QUERY_TIMEOUT));
        $this->assertSame('unknown', ErrorCodes::get_category(9999));

        $all = ErrorCodes::get_all_codes();
        $this->assertArrayHasKey('VALIDATION_INVALID_TABLE', $all);
        foreach ($all as $name => $info) {
            $this->assertStringStartsNotWith('Bilinmeyen', $info['message'], $name);
            $this->assertNotSame('unknown', $info['category'], $name);
        }
    }
}
