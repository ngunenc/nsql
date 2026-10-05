<?php

namespace Tests\Unit;

use nsql\database\orm\inflector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InflectorTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function plurals(): array
    {
        return [
            'regular' => ['user', 'users'],
            'consonant y' => ['category', 'categories'],
            'vowel y' => ['key', 'keys'],
            'day' => ['day', 'days'],
            's' => ['status', 'statuses'],
            'bus' => ['bus', 'buses'],
            'x' => ['box', 'boxes'],
            'ch' => ['match', 'matches'],
            'sh' => ['wish', 'wishes'],
            'z' => ['quiz', 'quizzes'],
            'irregular person' => ['person', 'people'],
            'irregular child' => ['child', 'children'],
            'f to ves' => ['leaf', 'leaves'],
            'regular f' => ['roof', 'roofs'],
            'regular o' => ['photo', 'photos'],
            'irregular o' => ['hero', 'heroes'],
            'uncountable' => ['news', 'news'],
            'uncountable data' => ['data', 'data'],
            'already plural irregular' => ['people', 'people'],
            'multi word' => ['user_category', 'user_categories'],
            'multi word irregular' => ['sales_person', 'sales_people'],
            'empty' => ['', ''],
        ];
    }

    #[DataProvider('plurals')]
    public function test_plural(string $singular, string $plural): void
    {
        $this->assertSame($plural, inflector::plural($singular));
    }

    public function test_snake(): void
    {
        $this->assertSame('user', inflector::snake('User'));
        $this->assertSame('blog_post', inflector::snake('BlogPost'));
        $this->assertSame('http_request', inflector::snake('HTTPRequest'));
        $this->assertSame('user_id2', inflector::snake('UserId2'));
    }

    public function test_table_for_class(): void
    {
        $this->assertSame('blog_posts', inflector::table_for_class('App\\Models\\BlogPost'));
        $this->assertSame('categories', inflector::table_for_class('Category'));
        $this->assertSame('people', inflector::table_for_class('App\\Person'));
        $this->assertSame('order_statuses', inflector::table_for_class('OrderStatus'));
    }
}
