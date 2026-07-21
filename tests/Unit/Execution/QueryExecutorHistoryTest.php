<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\DTO\ExecutionResult;
use SQLCraft\Execution\InMemoryQueryHistory;
use SQLCraft\Execution\QueryExecutor;

final class QueryExecutorHistoryTest extends TestCase
{
    public function testSuccessfulExecutionIsRecordedWithoutParameters(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('getDatabaseName')->willReturn('app');
        $connection->expects(self::once())->method('execute')->with('UPDATE users SET active = ?', [true])->willReturn(new ExecutionResult(1, '', 1.0, 'UPDATE users SET active = ?'));
        $history = new InMemoryQueryHistory();

        (new QueryExecutor($history))->execute($connection, 'UPDATE users SET active = ?', [true]);
        $entry = $history->getRecent('app')[0];

        self::assertSame('UPDATE users SET active = ?', $entry->sql);
        self::assertTrue($entry->success);
        self::assertNull($entry->errorMessage);
    }

    public function testFailedExecutionIsRecordedAndRethrown(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('getDatabaseName')->willReturn('app');
        $connection->expects(self::once())->method('execute')->willThrowException(new \RuntimeException('boom'));
        $history = new InMemoryQueryHistory();

        try {
            (new QueryExecutor($history))->execute($connection, 'DELETE FROM users');
            self::fail('Expected execution exception.');
        } catch (\RuntimeException $error) {
            self::assertSame('boom', $error->getMessage());
        }

        $entry = $history->getRecent('app')[0];
        self::assertFalse($entry->success);
        self::assertSame('boom', $entry->errorMessage);
    }
}
