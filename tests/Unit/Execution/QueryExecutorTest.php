<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use SQLCraft\Events\AfterQueryExecuted;
use SQLCraft\Events\BeforeQueryExecuted;
use SQLCraft\Events\QueryFailedEvent;
use SQLCraft\Events\SimpleEventDispatcher;
use SQLCraft\Events\SimpleListenerProvider;
use SQLCraft\Exceptions\OperationCancelledException;
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


    public function testBeforeQueryCanReplaceSqlAndParameters(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $expected = new ExecutionResult(1, '', 0.0, 'SELECT * FROM users WHERE tenant_id = ?');
        $connection->expects(self::once())->method('execute')->with($expected->sql, [7])->willReturn($expected);
        $provider = new SimpleListenerProvider();
        $provider->listen(BeforeQueryExecuted::class, static function (BeforeQueryExecuted $event): void {
            $event->replaceSql('SELECT * FROM users WHERE tenant_id = ?', [7]);
        });
        $events = new SimpleEventDispatcher($provider);

        self::assertSame($expected, (new QueryExecutor(events: $events, slowQueryThresholdMs: 0))->execute($connection, 'UPDATE users SET active = ?', [true]));
    }

    public function testCancelledQueryIsNotExecuted(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $connection->expects(self::never())->method('execute');
        $provider = new SimpleListenerProvider();
        $provider->listen(BeforeQueryExecuted::class, static function (BeforeQueryExecuted $event): void {
            $event->cancel('tenant is inactive');
        });
        $this->expectException(OperationCancelledException::class);

        (new QueryExecutor(events: new SimpleEventDispatcher($provider)))->execute($connection, 'UPDATE users SET active = ?', [true]);
    }

    public function testSuccessAndFailureEventsAreDispatched(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $connection->expects(self::exactly(2))->method('execute')->willReturnOnConsecutiveCalls(
            new ExecutionResult(1, '', 0.0, 'UPDATE users SET active = ?'),
            self::throwException(new \RuntimeException('failed')),
        );
        $received = [];
        $provider = new SimpleListenerProvider();
        $provider->listen(AfterQueryExecuted::class, static function (AfterQueryExecuted $event) use (&$received): void {
            $received[] = $event::class;
        });
        $provider->listen(QueryFailedEvent::class, static function (QueryFailedEvent $event) use (&$received): void {
            $received[] = $event::class;
        });
        $executor = new QueryExecutor(events: new SimpleEventDispatcher($provider), slowQueryThresholdMs: 0);
        $executor->execute($connection, 'UPDATE users SET active = ?', [true]);
        try {
            $executor->execute($connection, 'UPDATE users SET active = ?', [false]);
        } catch (\RuntimeException) {
        }

        self::assertSame([AfterQueryExecuted::class, QueryFailedEvent::class], $received);
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
