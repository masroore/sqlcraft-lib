<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Events;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\ValueObjects\ConnectionParameters;

interface ConnectionEventDispatcherInterface
{
    public function beforeConnectionOpened(string $name, ConnectionParameters $parameters): ?string;

    public function connectionOpened(string $name, string $driver, ?string $host, ?string $database, float $elapsedMs): void;

    public function connectionFailed(string $name, string $driver, \Throwable $error): void;

    public function connectionClosed(string $name, string $driver): void;

    public function beforeTransactionBegan(ConnectionInterface $connection, string $isolationLevel): ?string;

    public function transactionBegan(ConnectionInterface $connection, string $isolationLevel, ?string $savepoint): void;

    public function transactionCommitted(ConnectionInterface $connection, ?string $savepoint, float $elapsedMs): void;

    public function transactionRolledBack(ConnectionInterface $connection, ?string $savepoint, string $reason): void;
}
