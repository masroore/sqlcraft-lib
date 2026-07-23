<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Connection;

use PHPUnit\Framework\TestCase;
use SQLCraft\Connection\Transaction;
use SQLCraft\Connection\TransactionManager;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\DTO\ExecutionResult;

final class TransactionManagerTest extends TestCase
{
    public function test_begin_starts_an_outer_transaction(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('inTransaction')->willReturn(false);
        $connection->expects(self::once())
            ->method('beginTransaction')
            ->with('SERIALIZABLE')
            ->willReturn(new Transaction($connection, 'SERIALIZABLE'));

        $transaction = (new TransactionManager())->begin($connection, 'SERIALIZABLE');

        self::assertTrue($transaction->isActive());
    }

    public function test_begin_uses_a_savepoint_when_already_in_a_transaction(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('inTransaction')->willReturn(true);
        $connection->expects(self::once())
            ->method('execute')
            ->with(self::matchesRegularExpression('/^SAVEPOINT sp_[a-f0-9]{12}$/'))
            ->willReturn(new ExecutionResult(0, '', 0.0, 'SAVEPOINT'));

        $transaction = (new TransactionManager())->begin($connection);

        self::assertNotNull($transaction->savepointName);
        self::assertStringStartsWith('sp_', $transaction->savepointName);
    }

    public function test_transactional_commits_and_returns_the_callback_result(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('inTransaction')->willReturn(false);
        $connection->expects(self::once())->method('beginTransaction')->willReturn(new Transaction($connection));
        $connection->expects(self::once())->method('execute')->with('COMMIT')->willReturn(new ExecutionResult(0, '', 0.0, 'COMMIT'));

        $result = (new TransactionManager())->transactional(
            $connection,
            static fn (ConnectionInterface $received): string => $received === $connection ? 'done' : 'wrong',
        );

        self::assertSame('done', $result);
    }

    public function test_transactional_rolls_back_and_rethrows_callback_exceptions(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('inTransaction')->willReturn(false);
        $connection->expects(self::once())->method('beginTransaction')->willReturn(new Transaction($connection));
        $connection->expects(self::once())->method('execute')->with('ROLLBACK')->willReturn(new ExecutionResult(0, '', 0.0, 'ROLLBACK'));

        $this->expectExceptionObject(new \RuntimeException('failed'));
        (new TransactionManager())->transactional(
            $connection,
            static function (): never {
                throw new \RuntimeException('failed');
            },
        );
    }

    public function test_transaction_cannot_be_completed_twice(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('execute')->with('COMMIT')->willReturn(new ExecutionResult(0, '', 0.0, 'COMMIT'));
        $transaction = new Transaction($connection);
        $transaction->commit();

        $this->expectException(\LogicException::class);
        $transaction->commit();
    }
}
