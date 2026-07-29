<?php

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;
use nsql\database\nsql;

class QueryBuilderIntegrationTest extends DatabaseTestCase
{
    public function testQueryBuilder()
    {
        $builder = new \nsql\database\query_builder($this->db);
        
        $query = $builder->select('*')
            ->from('test_table')
            ->where('name', '=', 'Test Name')
            ->order_by('id', 'DESC')
            ->limit(10)
            ->get_query();
            
        $this->assertStringContainsString('SELECT * FROM `test_table`', $query);
        $this->assertStringContainsString('WHERE `name` =', $query);
        $this->assertStringContainsString('ORDER BY `id` DESC', $query);
        $this->assertStringContainsString('LIMIT', $query);
    }

    public function testQueryBuilderJoin()
    {
        $builder = new \nsql\database\query_builder($this->db);
        
        $query = $builder->select('test_table.*', 'users.name as user_name')
            ->from('test_table')
            ->join('users', 'test_table.user_id', '=', 'users.id')
            ->get_query();
            
        $this->assertStringContainsString('JOIN', $query);
        $this->assertStringContainsString('users', $query);
    }

    public function testQueryBuilderMultipleWhere()
    {
        $builder = new \nsql\database\query_builder($this->db);
        
        $query = $builder->select('*')
            ->from('test_table')
            ->where('name', '=', 'Test')
            ->where('id', '>', 0)
            ->get_query();
            
        $this->assertStringContainsString('WHERE', $query);
        // İki WHERE koşulu olmalı
        $whereCount = substr_count($query, 'WHERE');
        $this->assertGreaterThanOrEqual(1, $whereCount);
    }

    public function testQueryBuilderGroupBy()
    {
        $builder = new \nsql\database\query_builder($this->db);
        
        $query = $builder->select('category', 'COUNT(*) as count')
            ->from('test_table')
            ->group_by('category')
            ->get_query();
            
        $this->assertStringContainsString('GROUP BY', $query);
        $this->assertStringContainsString('category', $query);
    }

    public function testQueryBuilderGroupByMultiple()
    {
        $builder = new \nsql\database\query_builder($this->db);
        
        $query = $builder->select('category', 'status', 'COUNT(*) as count')
            ->from('test_table')
            ->group_by('category', 'status')
            ->get_query();
            
        $this->assertStringContainsString('GROUP BY', $query);
        $this->assertStringContainsString('category', $query);
        $this->assertStringContainsString('status', $query);
    }

    public function testQueryBuilderHaving()
    {
        $builder = new \nsql\database\query_builder($this->db);
        
        $query = $builder->select('category', 'COUNT(*) as count')
            ->from('test_table')
            ->group_by('category')
            ->having('COUNT(*)', '>', 5)
            ->get_query();
            
        $this->assertStringContainsString('GROUP BY', $query);
        $this->assertStringContainsString('HAVING', $query);
        $this->assertStringContainsString('COUNT(*)', $query);
    }

    public function testQueryBuilderGroupByWithHaving()
    {
        $builder = new \nsql\database\query_builder($this->db);
        
        $query = $builder->select('category', 'SUM(price) as total')
            ->from('test_table')
            ->group_by('category')
            ->having('SUM(price)', '>', 1000)
            ->order_by('total', 'DESC')
            ->get_query();
            
        $this->assertStringContainsString('GROUP BY', $query);
        $this->assertStringContainsString('HAVING', $query);
        $this->assertStringContainsString('ORDER BY', $query);
    }

    public function testQueryBuilderUnion()
    {
        $builder1 = new \nsql\database\query_builder($this->db);
        $builder1->select('name')
            ->from('test_table')
            ->where('id', '>', 0);

        $builder2 = new \nsql\database\query_builder($this->db);
        $builder2->select('name')
            ->from('users')
            ->where('id', '>', 0);

        $query = $builder1->union($builder2)->get_query();
        
        $this->assertStringContainsString('UNION', $query);
        $this->assertStringContainsString('test_table', $query);
        $this->assertStringContainsString('users', $query);
    }

    public function testQueryBuilderUnionAll()
    {
        $builder1 = new \nsql\database\query_builder($this->db);
        $builder1->select('name')
            ->from('test_table');

        $builder2 = new \nsql\database\query_builder($this->db);
        $builder2->select('name')
            ->from('users');

        $query = $builder1->union($builder2, true)->get_query();
        
        $this->assertStringContainsString('UNION ALL', $query);
    }

    public function testQueryBuilderLeftJoin()
    {
        $builder = new \nsql\database\query_builder($this->db);
        
        $query = $builder->select('test_table.*', 'users.name as user_name')
            ->from('test_table')
            ->left_join('users', 'test_table.user_id', '=', 'users.id')
            ->get_query();
            
        $this->assertStringContainsString('LEFT JOIN', $query);
        $this->assertStringContainsString('users', $query);
    }

    public function testQueryBuilderRightJoin()
    {
        $builder = new \nsql\database\query_builder($this->db);
        
        $query = $builder->select('*')
            ->from('test_table')
            ->right_join('users', 'test_table.user_id', '=', 'users.id')
            ->get_query();
            
        $this->assertStringContainsString('RIGHT JOIN', $query);
    }

    public function testQueryBuilderFullJoin()
    {
        $builder = new \nsql\database\query_builder($this->db);
        
        $query = $builder->select('*')
            ->from('test_table')
            ->full_join('users', 'test_table.user_id', '=', 'users.id')
            ->get_query();
            
        $this->assertStringContainsString('FULL JOIN', $query);
    }

    public function testQueryBuilderInnerJoin()
    {
        $builder = new \nsql\database\query_builder($this->db);
        
        $query = $builder->select('*')
            ->from('test_table')
            ->inner_join('users', 'test_table.user_id', '=', 'users.id')
            ->get_query();
            
        $this->assertStringContainsString('INNER JOIN', $query);
    }

    public function testQueryBuilderCrossJoin()
    {
        $builder = new \nsql\database\query_builder($this->db);
        
        $query = $builder->select('*')
            ->from('test_table')
            ->cross_join('users')
            ->get_query();
            
        $this->assertStringContainsString('CROSS JOIN', $query);
        $this->assertStringNotContainsString('ON', $query); // CROSS JOIN'de ON condition yok
    }

    public function testQueryBuilderMultipleJoins()
    {
        $builder = new \nsql\database\query_builder($this->db);
        
        $query = $builder->select('*')
            ->from('test_table')
            ->left_join('users', 'test_table.user_id', '=', 'users.id')
            ->right_join('categories', 'test_table.category_id', '=', 'categories.id')
            ->get_query();
            
        $this->assertStringContainsString('LEFT JOIN', $query);
        $this->assertStringContainsString('RIGHT JOIN', $query);
        $this->assertStringContainsString('users', $query);
        $this->assertStringContainsString('categories', $query);
    }

    public function testQueryBuilderJoinWithClosure()
    {
        $builder = new \nsql\database\query_builder($this->db);
        
        $query = $builder->select('*')
            ->from('test_table')
            ->left_join('users', function($q) {
                return 'test_table.user_id = users.id AND users.active = 1';
            })
            ->get_query();
            
        $this->assertStringContainsString('LEFT JOIN', $query);
        $this->assertStringContainsString('users.active = 1', $query);
    }

    public function testQueryBuilderWhereSubquery()
    {
        $subquery = new \nsql\database\query_builder($this->db);
        $subquery->select('id')
            ->from('users')
            ->where('active', '=', 1);

        $builder = new \nsql\database\query_builder($this->db);
        $query = $builder->select('*')
            ->from('test_table')
            ->where('user_id', 'IN', $subquery)
            ->get_query();
            
        $this->assertStringContainsString('user_id', $query);
        $this->assertStringContainsString('SELECT', $query);
        $this->assertStringContainsString('users', $query);
    }

    public function testQueryBuilderWhereInSubquery()
    {
        $subquery = new \nsql\database\query_builder($this->db);
        $subquery->select('id')
            ->from('users')
            ->where('active', '=', 1);

        $builder = new \nsql\database\query_builder($this->db);
        $query = $builder->select('*')
            ->from('test_table')
            ->where_in_subquery('user_id', $subquery)
            ->get_query();
            
        $this->assertStringContainsString('IN', $query);
        $this->assertStringContainsString('user_id', $query);
    }

    public function testQueryBuilderWhereExists()
    {
        $subquery = new \nsql\database\query_builder($this->db);
        $subquery->select('1')
            ->from('users')
            ->where('users.id', '=', 'test_table.user_id');

        $builder = new \nsql\database\query_builder($this->db);
        $query = $builder->select('*')
            ->from('test_table')
            ->where_exists($subquery)
            ->get_query();
            
        $this->assertStringContainsString('EXISTS', $query);
    }

    public function testQueryBuilderWhereNotExists()
    {
        $subquery = new \nsql\database\query_builder($this->db);
        $subquery->select('1')
            ->from('users')
            ->where('users.id', '=', 'test_table.user_id');

        $builder = new \nsql\database\query_builder($this->db);
        $query = $builder->select('*')
            ->from('test_table')
            ->where_not_exists($subquery)
            ->get_query();
            
        $this->assertStringContainsString('NOT EXISTS', $query);
    }

    public function testQueryBuilderSelectSubquery()
    {
        $subquery = new \nsql\database\query_builder($this->db);
        $subquery->select('COUNT(*)')
            ->from('users')
            ->where('active', '=', 1);

        $builder = new \nsql\database\query_builder($this->db);
        $query = $builder->select('name', $subquery)
            ->from('test_table')
            ->get_query();
            
        $this->assertStringContainsString('SELECT', $query);
        $this->assertStringContainsString('COUNT(*)', $query);
    }

    public function testQueryBuilderFromSubquery()
    {
        $subquery = new \nsql\database\query_builder($this->db);
        $subquery->select('*')
            ->from('users')
            ->where('active', '=', 1);

        $builder = new \nsql\database\query_builder($this->db);
        $query = $builder->select('*')
            ->from($subquery, 'active_users')
            ->get_query();
            
        $this->assertStringContainsString('FROM', $query);
        $this->assertStringContainsString('active_users', $query);
    }

    public function testQueryBuilderHavingSubquery()
    {
        $subquery = new \nsql\database\query_builder($this->db);
        $subquery->select('AVG(price)')
            ->from('test_table');

        $builder = new \nsql\database\query_builder($this->db);
        $query = $builder->select('category', 'SUM(price) as total')
            ->from('test_table')
            ->group_by('category')
            ->having('SUM(price)', '>', $subquery)
            ->get_query();
            
        $this->assertStringContainsString('HAVING', $query);
        $this->assertStringContainsString('AVG(price)', $query);
    }
}
