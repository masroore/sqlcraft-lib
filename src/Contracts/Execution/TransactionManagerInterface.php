<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Execution;

use SQLCraft\Connection\Transaction;
use SQLCraft\Contracts\Connection\ConnectionInterface;

interface TransactionManagerInterface
{
    public function begin(
        ConnectionInterface $connection,
        string $isolationLevel = '',
    ): Transaction;

    /** @param callable(ConnectionInterface): mixed $callback */
    public function transactional(ConnectionInterface $connection, callable $callback): mixed;
}
