<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Query;

use PHPUnit\Framework\TestCase;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\Query\ColumnSelection;
use SQLCraft\Query\OrderByClause;
use SQLCraft\Query\PaginationParams;
use SQLCraft\Query\SelectQuery;
use SQLCraft\Query\SelectQueryRenderer;
use SQLCraft\Query\WhereCondition;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final class SelectQueryTest extends TestCase
{
    public function testRendersBoundWhereValuesAndPagination(): void
    {
        $query = (new SelectQuery(new QualifiedName(new Identifier('users'))))
            ->withWhere(new WhereCondition(new Identifier('email'), '=', 'ada@example.test'))
            ->withOrderBy(new OrderByClause(new Identifier('id'), descending: true));
        $query = new SelectQuery($query->table, $query->columns, $query->where, $query->orderBy, limit: 10, offset: 20);

        $rendered = (new SelectQueryRenderer(new SqlitePlatform()))->render($query);

        self::assertSame('SELECT * FROM "users" WHERE "email" = ? ORDER BY "id" DESC LIMIT 10 OFFSET 20', $rendered['sql']);
        self::assertSame(['ada@example.test'], $rendered['params']);
        self::assertStringNotContainsString('ada@example.test', $rendered['sql']);
    }

    public function testRendersAggregatesInConditionsAndQualifiedNames(): void
    {
        $query = new SelectQuery(
            new QualifiedName(new Identifier('users'), new Identifier('public')),
            [new ColumnSelection(new Identifier('id'), 'COUNT', new Identifier('total'))],
            [new WhereCondition(new Identifier('id'), 'IN', [1, 2, 3])],
            groupBy: ['id'],
        );

        $rendered = (new SelectQueryRenderer(new SqlitePlatform()))->render($query);

        self::assertSame('SELECT COUNT("id") AS "total" FROM "public"."users" WHERE "id" IN (?, ?, ?) GROUP BY "id"', $rendered['sql']);
        self::assertSame([1, 2, 3], $rendered['params']);
    }

    public function testNullAndBetweenConditionsDoNotInterpolateValues(): void
    {
        $query = new SelectQuery(new QualifiedName(new Identifier('users')), where: [
            new WhereCondition(new Identifier('deleted_at'), 'IS NULL', null),
            new WhereCondition(new Identifier('age'), 'BETWEEN', [18, 65]),
        ]);

        $rendered = (new SelectQueryRenderer(new SqlitePlatform()))->render($query);

        self::assertSame('SELECT * FROM "users" WHERE "deleted_at" IS NULL AND "age" BETWEEN ? AND ?', $rendered['sql']);
        self::assertSame([18, 65], $rendered['params']);
    }

    public function testRejectsUnknownOperatorAndInvalidPagination(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WhereCondition(new Identifier('id'), 'OR 1=1', 1);
    }

    public function testPaginationParamsValidateAndCalculateOffset(): void
    {
        self::assertSame(20, (new PaginationParams(3, 10))->offset());
        $this->expectException(\InvalidArgumentException::class);
        new PaginationParams(0, 10);
    }
}
