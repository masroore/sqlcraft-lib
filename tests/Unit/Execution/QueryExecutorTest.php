<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\DTO\ExecutionResult;
use SQLCraft\Execution\QueryExecutor;
use SQLCraft\Platform\SqlitePlatform;

final class QueryExecutorTest extends TestCase
{
    public function testExecuteDelegatesToConnection(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $expected = new ExecutionResult(2, '', 1.5, 'UPDATE users SET active = ?');
        $connection->expects(self::once())->method('execute')->with($expected->sql, [true])->willReturn($expected);

        self::assertSame($expected, (new QueryExecutor())->execute($connection, $expected->sql, [true]));
    }

    public function testQueryStreamsByDefaultAndBuffersWhenRequested(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $result = self::createMock(ResultInterface::class);
        $calls = [];
        $connection->expects(self::exactly(2))->method('query')->willReturnCallback(
            function (string $sql, array $params, bool $streaming) use (&$calls, $result): ResultInterface {
                $calls[] = [$sql, $params, $streaming];

                return $result;
            },
        );
        $executor = new QueryExecutor();

        self::assertSame($result, $executor->query($connection, 'SELECT * FROM users'));
        self::assertSame($result, $executor->query($connection, 'SELECT * FROM users', buffered: true));
        self::assertSame([
            ['SELECT * FROM users', [], true],
            ['SELECT * FROM users', [], false],
        ], $calls);
    }

    public function testExecuteDdlDelegatesAndDiscardsResult(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('execute')->with('CREATE TABLE users (id INTEGER)', [])->willReturn(new ExecutionResult(0, '', 1.0, 'CREATE TABLE users (id INTEGER)'));

        (new QueryExecutor())->executeDdl($connection, 'CREATE TABLE users (id INTEGER)');
    }

    public function testZeroTimeoutUsesNormalStreamingQuery(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $result = self::createMock(ResultInterface::class);
        $connection->expects(self::once())->method('query')->with('SELECT 1', [], true)->willReturn($result);

        self::assertSame($result, (new QueryExecutor())->queryWithTimeout($connection, 'SELECT 1'));
    }

    public function testUnsupportedTimeoutReturnsNull(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('getPlatform')->willReturn(new SqlitePlatform());
        $connection->expects(self::never())->method('query');

        self::assertNull((new QueryExecutor())->queryWithTimeout($connection, 'SELECT 1', timeoutMs: 100));
    }

    public function testNegativeTimeoutIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new QueryExecutor())->queryWithTimeout(self::createMock(ConnectionInterface::class), 'SELECT 1', timeoutMs: -1);
    }
}
