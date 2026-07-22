<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Query;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Platform\PlatformInterface;
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
    public function test_renders_bound_where_values_and_pagination(): void
    {
        $query = (new SelectQuery(new QualifiedName(new Identifier('users'))))
            ->withWhere(new WhereCondition(new Identifier('email'), '=', 'ada@example.test'))
            ->withOrderBy(new OrderByClause(new Identifier('id'), descending: true));
        $query = new SelectQuery($query->table, $query->columns, $query->where, $query->orderBy, limit: 10, offset: 20);

        $rendered = (new SelectQueryRenderer(new SqlitePlatform))->render($query);

        self::assertSame('SELECT * FROM "users" WHERE "email" = ? ORDER BY "id" DESC LIMIT 10 OFFSET 20', $rendered['sql']);
        self::assertSame(['ada@example.test'], $rendered['params']);
        self::assertStringNotContainsString('ada@example.test', $rendered['sql']);
    }

    public function test_renders_aggregates_in_conditions_and_qualified_names(): void
    {
        $query = new SelectQuery(
            new QualifiedName(new Identifier('users'), new Identifier('public')),
            [new ColumnSelection(new Identifier('id'), 'COUNT', new Identifier('total'))],
            [new WhereCondition(new Identifier('id'), 'IN', [1, 2, 3])],
            groupBy: ['id'],
        );

        $rendered = (new SelectQueryRenderer(new SqlitePlatform))->render($query);

        self::assertSame('SELECT COUNT("id") AS "total" FROM "public"."users" WHERE "id" IN (?, ?, ?) GROUP BY "id"', $rendered['sql']);
        self::assertSame([1, 2, 3], $rendered['params']);
    }

    public function test_null_and_between_conditions_do_not_interpolate_values(): void
    {
        $query = new SelectQuery(new QualifiedName(new Identifier('users')), where: [
            new WhereCondition(new Identifier('deleted_at'), 'IS NULL', null),
            new WhereCondition(new Identifier('age'), 'BETWEEN', [18, 65]),
        ]);

        $rendered = (new SelectQueryRenderer(new SqlitePlatform))->render($query);

        self::assertSame('SELECT * FROM "users" WHERE "deleted_at" IS NULL AND "age" BETWEEN ? AND ?', $rendered['sql']);
        self::assertSame([18, 65], $rendered['params']);
    }

    public function test_rejects_unknown_operator_and_invalid_pagination(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WhereCondition(new Identifier('id'), 'OR 1=1', 1);
    }

    public function test_pagination_params_validate_and_calculate_offset(): void
    {
        self::assertSame(20, (new PaginationParams(3, 10))->offset());
        $this->expectException(\InvalidArgumentException::class);
        new PaginationParams(0, 10);
    }

    public function test_aggregate_functions_come_from_the_platform_allowlist(): void
    {
        $platform = self::createMock(PlatformInterface::class);
        $platform->method('getSupportedAggregateFunctions')->willReturn(['COUNT']);
        $platform->method('quoteIdentifier')->willReturnCallback(static fn (Identifier $identifier): string => '"' . $identifier->name . '"');
        $platform->method('getOperators')->willReturn(['=']);
        $platform->method('getName')->willReturn('test');
        $query = new SelectQuery(new QualifiedName(new Identifier('users')), [new ColumnSelection(new Identifier('id'), 'SUM')]);

        $this->expectException(\InvalidArgumentException::class);
        (new SelectQueryRenderer($platform))->render($query);
    }

    public function test_aggregate_injection_is_rejected_by_constructor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ColumnSelection(new Identifier('id'), 'COUNT/**/');
    }
}
