<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Platform;

use PHPUnit\Framework\TestCase;
use SQLCraft\Platform\MySQLPlatform;
use SQLCraft\Platform\PostgreSQLPlatform;
use SQLCraft\Platform\SqlitePlatform;

final class ExplainDialectTest extends TestCase
{
    public function testMySqlExplainForms(): void
    {
        $platform = new MySQLPlatform();

        self::assertSame('EXPLAIN FORMAT=JSON SELECT 1', $platform->getExplainSql('SELECT 1'));
        self::assertSame('EXPLAIN ANALYZE SELECT 1', $platform->getExplainSql('SELECT 1', true));
    }

    public function testPostgreSqlExplainForms(): void
    {
        $platform = new PostgreSQLPlatform();

        self::assertSame('EXPLAIN (FORMAT JSON) SELECT 1', $platform->getExplainSql('SELECT 1'));
        self::assertSame('EXPLAIN (FORMAT JSON, ANALYZE) SELECT 1', $platform->getExplainSql('SELECT 1', true));
    }

    public function testSqliteUsesQueryPlanWithoutExecutingTheSelect(): void
    {
        self::assertSame('EXPLAIN QUERY PLAN SELECT 1', (new SqlitePlatform())->getExplainSql('SELECT 1', true));
    }
}
