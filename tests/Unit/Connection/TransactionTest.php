<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Connection;

use PHPUnit\Framework\TestCase;
use SQLCraft\Connection\Transaction;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\DTO\ExecutionResult;

final class TransactionTest extends TestCase
{
    public function testCommitExecutesTheCommitStatement(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())
            ->method('execute')
            ->with('COMMIT')
            ->willReturn(new ExecutionResult(0, '', 0.0, 'COMMIT'));

        $transaction = new Transaction($connection);

        self::assertTrue($transaction->isActive());
        $transaction->commit();
        self::assertFalse($transaction->isActive());
    }

    public function testNestedRollbackUsesItsSavepoint(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())
            ->method('execute')
            ->with('ROLLBACK TO SAVEPOINT nested_1')
            ->willReturn(new ExecutionResult(0, '', 0.0, 'ROLLBACK TO SAVEPOINT nested_1'));

        $transaction = new Transaction($connection, savepointName: 'nested_1');

        $transaction->rollback();

        self::assertFalse($transaction->isActive());
    }
}
