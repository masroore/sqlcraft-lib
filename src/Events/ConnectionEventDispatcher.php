<?php

declare(strict_types=1);

namespace SQLCraft\Events;

use Psr\EventDispatcher\EventDispatcherInterface;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Events\ConnectionEventDispatcherInterface;
use SQLCraft\ValueObjects\ConnectionParameters;

final readonly class ConnectionEventDispatcher implements ConnectionEventDispatcherInterface
{
    public function __construct(private EventDispatcherInterface $dispatcher)
    {
    }

    #[\Override]
    public function beforeConnectionOpened(string $name, ConnectionParameters $parameters): ?string
    {
        $event = new BeforeConnectionOpened($name, $parameters);
        $this->dispatcher->dispatch($event);

        return $event->isCancelled() ? ($event->cancelReason === '' ? 'Connection opening was cancelled.' : $event->cancelReason) : null;
    }

    #[\Override]
    public function connectionOpened(string $name, string $driver, ?string $host, ?string $database, float $elapsedMs, ?ConnectionInterface $connection = null): void
    {
        $this->dispatcher->dispatch(new ConnectionOpenedEvent($name, $driver, $host, $database, $elapsedMs, $connection));
    }

    #[\Override]
    public function connectionFailed(string $name, string $driver, \Throwable $error): void
    {
        $this->dispatcher->dispatch(new ConnectionFailedEvent($name, $driver, $error));
    }

    #[\Override]
    public function connectionClosed(string $name, string $driver): void
    {
        $this->dispatcher->dispatch(new ConnectionClosedEvent($name, $driver));
    }

    #[\Override]
    public function beforeTransactionBegan(ConnectionInterface $connection, string $isolationLevel): ?string
    {
        $event = new BeforeTransactionBegan($connection, $isolationLevel);
        $this->dispatcher->dispatch($event);

        return $event->isCancelled() ? ($event->cancelReason === '' ? 'Transaction opening was cancelled.' : $event->cancelReason) : null;
    }

    #[\Override]
    public function transactionBegan(ConnectionInterface $connection, string $isolationLevel, ?string $savepoint): void
    {
        $this->dispatcher->dispatch(new TransactionBeganEvent($connection, $isolationLevel, $savepoint));
    }

    #[\Override]
    public function transactionCommitted(ConnectionInterface $connection, ?string $savepoint, float $elapsedMs): void
    {
        $this->dispatcher->dispatch(new TransactionCommittedEvent($connection, $savepoint, $elapsedMs));
    }

    #[\Override]
    public function transactionRolledBack(ConnectionInterface $connection, ?string $savepoint, string $reason): void
    {
        $this->dispatcher->dispatch(new TransactionRolledBackEvent($connection, $savepoint, $reason));
    }
}
