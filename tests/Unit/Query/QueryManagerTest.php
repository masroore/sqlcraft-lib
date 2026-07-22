<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Execution\QueryExecutorInterface;
use SQLCraft\Contracts\Execution\StatementBatch;
use SQLCraft\Contracts\Execution\StatementSplitterInterface;
use SQLCraft\DTO\ExecutionResult;
use SQLCraft\Execution\QueryManager;

final class QueryManagerTest extends TestCase
{
    public function test_delegates_execution_and_splitting(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $executor = self::createMock(QueryExecutorInterface::class);
        $executor->expects(self::once())->method('execute')->with($connection, 'SELECT 1', [])->willReturn(new ExecutionResult(0, '', 1.0, 'SELECT 1'));
        $splitter = self::createMock(StatementSplitterInterface::class);
        $batch = new StatementBatch(['SELECT 1']);
        $splitter->expects(self::once())->method('split')->with('SELECT 1;')->willReturn($batch);

        $manager = new QueryManager($executor, $splitter);
        self::assertSame($batch, $manager->split('SELECT 1;'));
        self::assertSame('SELECT 1', $manager->execute($connection, 'SELECT 1')->sql);
    }

    public function test_requires_configured_batch_executor(): void
    {
        $this->expectException(\LogicException::class);
        iterator_to_array((new QueryManager(self::createMock(QueryExecutorInterface::class)))->executeBatch(self::createMock(ConnectionInterface::class), new StatementBatch(['SELECT 1'])));
    }
}
