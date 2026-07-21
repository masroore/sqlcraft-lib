<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Query;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\Contracts\Execution\QueryExecutorInterface;
use SQLCraft\Contracts\Query\TableStatusProviderInterface;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\Query\Page;
use SQLCraft\Query\Paginator;
use SQLCraft\Query\PaginationParams;
use SQLCraft\Query\SelectQuery;
use SQLCraft\Query\SelectQueryRenderer;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final class PaginatorTest extends TestCase
{
    public function testUsesApproximateTableStatusRowsWhenQueryIsUnfiltered(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $result = self::createMock(ResultInterface::class);
        $result->expects(self::once())->method('fetchAll')->willReturn([['id' => 1], ['id' => 2]]);
        $executor = self::createMock(QueryExecutorInterface::class);
        $executor->expects(self::once())->method('query')->with($connection, 'SELECT * FROM "users" LIMIT 2 OFFSET 0', [], true)->willReturn($result);
        $status = self::createMock(TableStatusProviderInterface::class);
        $status->expects(self::once())->method('getApproximateRowCount')->willReturn(10);

        $page = (new Paginator($executor, new SelectQueryRenderer(new SqlitePlatform()), $status))
            ->paginate($connection, new SelectQuery(new QualifiedName(new Identifier('users'))), new PaginationParams(1, 2));

        self::assertSame(10, $page->totalRows);
        self::assertTrue($page->totalApprox);
        self::assertTrue($page->hasMore);
        self::assertSame(5, $page->totalPages());
    }

    public function testRunsExactCountForFilteredQuery(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $pageRows = self::createMock(ResultInterface::class);
        $pageRows->expects(self::once())->method('fetchAll')->willReturn([['id' => 1]]);
        $countRows = self::createMock(ResultInterface::class);
        $countRows->expects(self::once())->method('fetchColumn')->willReturn([5]);
        $executor = self::createMock(QueryExecutorInterface::class);
        $executor->expects(self::exactly(2))->method('query')->willReturnCallback(function (ConnectionInterface $connection, string $sql, array $params, bool $buffered) use ($pageRows, $countRows): ResultInterface {
            self::assertTrue($buffered);
            if (str_starts_with($sql, 'SELECT COUNT(*)')) {
                self::assertSame(['Ada'], $params);
                return $countRows;
            }
            self::assertSame('SELECT * FROM "users" WHERE "name" = ? LIMIT 2 OFFSET 2', $sql);
            self::assertSame(['Ada'], $params);
            return $pageRows;
        });

        $query = new SelectQuery(new QualifiedName(new Identifier('users')), where: [
            new \SQLCraft\Query\WhereCondition(new Identifier('name'), '=', 'Ada'),
        ]);
        $page = (new Paginator($executor, new SelectQueryRenderer(new SqlitePlatform())))
            ->paginate($connection, $query, new PaginationParams(2, 2));

        self::assertSame(5, $page->totalRows);
        self::assertFalse($page->totalApprox);
        self::assertTrue($page->hasMore);
    }

    public function testRejectsLimitAboveConfiguredMaximum(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Paginator(self::createMock(QueryExecutorInterface::class), new SelectQueryRenderer(new SqlitePlatform()), maximumLimit: 10))
            ->paginate(self::createMock(ConnectionInterface::class), new SelectQuery(new QualifiedName(new Identifier('users'))), new PaginationParams(1, 11));
    }
}
