<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Platform;

use PHPUnit\Framework\TestCase;
use SQLCraft\Platform\MySQLPlatform;
use SQLCraft\Platform\PostgreSQLPlatform;
use SQLCraft\Platform\SqlitePlatform;

final class ExplainDialectTest extends TestCase
{
    public function test_my_sql_explain_forms(): void
    {
        $platform = new MySQLPlatform();

        self::assertSame('EXPLAIN FORMAT=JSON SELECT 1', $platform->getExplainSql('SELECT 1'));
        self::assertSame('EXPLAIN ANALYZE SELECT 1', $platform->getExplainSql('SELECT 1', true));
    }

    public function test_postgre_sql_explain_forms(): void
    {
        $platform = new PostgreSQLPlatform();

        self::assertSame('EXPLAIN (FORMAT JSON) SELECT 1', $platform->getExplainSql('SELECT 1'));
        self::assertSame('EXPLAIN (FORMAT JSON, ANALYZE) SELECT 1', $platform->getExplainSql('SELECT 1', true));
    }

    public function test_sqlite_uses_query_plan_without_executing_the_select(): void
    {
        self::assertSame('EXPLAIN QUERY PLAN SELECT 1', (new SqlitePlatform())->getExplainSql('SELECT 1', true));
    }
}
