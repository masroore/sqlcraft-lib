<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\Contracts\Execution\QueryInterceptorInterface;
use SQLCraft\DTO\ExecutionResult;
use SQLCraft\Events\AfterQueryExecuted;
use SQLCraft\Events\BeforeQueryExecuted;
use SQLCraft\Events\QueryFailedEvent;
use SQLCraft\Events\SimpleEventDispatcher;
use SQLCraft\Events\SimpleListenerProvider;
use SQLCraft\Exceptions\OperationCancelledException;
use SQLCraft\Execution\QueryExecutor;
use SQLCraft\Execution\QueryInterceptorPipeline;
use SQLCraft\Execution\QueryRequest;
use SQLCraft\Platform\SqlitePlatform;

final class QueryExecutorTest extends TestCase
{
    public function test_execute_delegates_to_connection(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $expected = new ExecutionResult(2, '', 1.5, 'UPDATE users SET active = ?');
        $connection->expects(self::once())->method('execute')->with($expected->sql, [true])->willReturn($expected);

        self::assertSame($expected, (new QueryExecutor)->execute($connection, $expected->sql, [true]));
    }

    public function test_query_streams_by_default_and_buffers_when_requested(): void
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
        $executor = new QueryExecutor;

        self::assertSame($result, $executor->query($connection, 'SELECT * FROM users'));
        self::assertSame($result, $executor->query($connection, 'SELECT * FROM users', buffered: true));
        self::assertSame([
            ['SELECT * FROM users', [], true],
            ['SELECT * FROM users', [], false],
        ], $calls);
    }

    public function test_execute_ddl_delegates_and_discards_result(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('execute')->with('CREATE TABLE users (id INTEGER)', [])->willReturn(new ExecutionResult(0, '', 1.0, 'CREATE TABLE users (id INTEGER)'));

        (new QueryExecutor)->executeDdl($connection, 'CREATE TABLE users (id INTEGER)');
    }

    public function test_before_query_can_replace_sql_and_parameters(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $expected = new ExecutionResult(1, '', 0.0, 'SELECT * FROM users WHERE tenant_id = ?');
        $connection->expects(self::once())->method('execute')->with($expected->sql, [7])->willReturn($expected);
        $interceptor = self::createMock(QueryInterceptorInterface::class);
        $interceptor->expects(self::once())->method('intercept')->willReturnCallback(
            static fn (QueryRequest $request): QueryRequest => $request->withSqlAndParams('SELECT * FROM users WHERE tenant_id = ?', [7]),
        );

        self::assertSame($expected, (new QueryExecutor(slowQueryThresholdMs: 0, pipeline: new QueryInterceptorPipeline([$interceptor])))->execute($connection, 'UPDATE users SET active = ?', [true]));
    }

    public function test_cancelled_query_is_not_executed(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $connection->expects(self::never())->method('execute');
        $provider = new SimpleListenerProvider;
        $provider->listen(BeforeQueryExecuted::class, static function (BeforeQueryExecuted $event): void {
            $event->cancel('tenant is inactive');
        });
        $this->expectException(OperationCancelledException::class);

        (new QueryExecutor(events: new SimpleEventDispatcher($provider)))->execute($connection, 'UPDATE users SET active = ?', [true]);
    }

    public function test_success_and_failure_events_are_dispatched(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $connection->expects(self::exactly(2))->method('execute')->willReturnOnConsecutiveCalls(
            new ExecutionResult(1, '', 0.0, 'UPDATE users SET active = ?'),
            self::throwException(new \RuntimeException('failed')),
        );
        $received = [];
        $provider = new SimpleListenerProvider;
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

    public function test_zero_timeout_uses_normal_streaming_query(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $result = self::createMock(ResultInterface::class);
        $connection->expects(self::once())->method('query')->with('SELECT 1', [], true)->willReturn($result);

        self::assertSame($result, (new QueryExecutor)->queryWithTimeout($connection, 'SELECT 1'));
    }

    public function test_unsupported_timeout_returns_null(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('getPlatform')->willReturn(new SqlitePlatform);
        $connection->expects(self::never())->method('query');

        self::assertNull((new QueryExecutor)->queryWithTimeout($connection, 'SELECT 1', timeoutMs: 100));
    }

    public function test_negative_timeout_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new QueryExecutor)->queryWithTimeout(self::createMock(ConnectionInterface::class), 'SELECT 1', timeoutMs: -1);
    }
}
