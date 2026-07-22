<?php

declare(strict_types=1);

namespace SQLCraft\Connection;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Execution\TransactionManagerInterface;

/** @internal */
final class TransactionManager implements TransactionManagerInterface
{
    #[\Override]
    public function begin(
        ConnectionInterface $connection,
        string $isolationLevel = '',
    ): Transaction {
        if ($connection->inTransaction()) {
            $savepoint = 'sp_'.bin2hex(random_bytes(6));
            $connection->execute('SAVEPOINT '.$savepoint);

            return new Transaction($connection, savepointName: $savepoint);
        }

        return $connection->beginTransaction($isolationLevel);
    }

    /**
     * @template T
     *
     * @param  callable(ConnectionInterface): T  $callback
     * @return T
     */
    #[\Override]
    public function transactional(ConnectionInterface $connection, callable $callback): mixed
    {
        $transaction = $this->begin($connection);

        try {
            $result = $callback($connection);
            $transaction->commit();

            return $result;
        } catch (\Throwable $exception) {
            if ($transaction->isActive()) {
                $transaction->rollback();
            }

            throw $exception;
        }
    }
}
