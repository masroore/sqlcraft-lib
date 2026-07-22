<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\Contracts\Execution\QueryExecutorInterface;
use SQLCraft\Contracts\Execution\StatementBatch;
use SQLCraft\DTO\ExecutionResult;
use SQLCraft\Execution\BatchExecutor;

final class BatchExecutorTest extends TestCase
{
    public function test_executes_queries_and_commands_in_order(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $rows = self::createMock(ResultInterface::class);
        $executor = self::createMock(QueryExecutorInterface::class);
        $executor->expects(self::once())->method('execute')->with($connection, 'INSERT INTO users VALUES (1)')->willReturn(new ExecutionResult(1, '', 1.0, 'INSERT INTO users VALUES (1)'));
        $executor->expects(self::once())->method('query')->with($connection, 'SELECT * FROM users')->willReturn($rows);

        $results = iterator_to_array((new BatchExecutor($executor))->executeBatch($connection, new StatementBatch([
            'INSERT INTO users VALUES (1)',
            'SELECT * FROM users',
        ])));

        self::assertSame(1, $results[0]->result?->affectedRows);
        self::assertSame($rows, $results[1]->rows);
        self::assertNull($results[0]->error);
        self::assertNull($results[1]->error);
    }

    public function test_can_collect_errors_and_continue(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $executor = self::createMock(QueryExecutorInterface::class);
        $executor->expects(self::exactly(2))->method('execute')->willReturnCallback(static function (ConnectionInterface $connection, string $sql): ExecutionResult {
            if ($sql === 'bad') {
                throw new \RuntimeException('failed');
            }

            return new ExecutionResult(0, '', 1.0, $sql);
        });

        $results = iterator_to_array((new BatchExecutor($executor))->executeBatch($connection, new StatementBatch(['bad', 'good']), stopOnError: false));

        self::assertInstanceOf(\RuntimeException::class, $results[0]->error);
        self::assertSame('good', $results[1]->result?->sql);
    }

    public function test_stops_on_first_error_by_default(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $executor = self::createMock(QueryExecutorInterface::class);
        $executor->expects(self::once())->method('execute')->willThrowException(new \RuntimeException('failed'));

        $this->expectException(\RuntimeException::class);
        iterator_to_array((new BatchExecutor($executor))->executeBatch($connection, new StatementBatch(['bad', 'never'])));
    }

    public function test_rejects_batches_above_configured_maximum(): void
    {
        $executor = self::createMock(QueryExecutorInterface::class);
        $batchExecutor = new BatchExecutor($executor, maximumStatements: 1);

        $this->expectException(\InvalidArgumentException::class);
        iterator_to_array($batchExecutor->executeBatch(
            self::createMock(ConnectionInterface::class),
            new StatementBatch(['one', 'two']),
        ));
    }
}
