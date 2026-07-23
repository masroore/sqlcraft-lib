<?php

declare(strict_types=1);

namespace SQLCraft\Connection;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Events\ConnectionEventDispatcherInterface;

final class Transaction
{
    private bool $committed = false;

    private bool $rolledBack = false;

    public function __construct(
        private readonly ConnectionInterface $connection,
        public readonly string $isolationLevel = '',
        public readonly ?string $savepointName = null,
        private readonly ?ConnectionEventDispatcherInterface $events = null,
    ) {
    }

    public function commit(): void
    {
        $this->assertActive();

        $startedAt = hrtime(true);
        if ($this->savepointName !== null) {
            $this->connection->execute("RELEASE SAVEPOINT {$this->savepointName}");
        } else {
            $this->connection->execute('COMMIT');
        }

        $this->committed = true;
        $this->events?->transactionCommitted(
            $this->connection,
            $this->savepointName,
            (hrtime(true) - $startedAt) / 1_000_000,
        );
    }

    public function rollback(): void
    {
        $this->assertActive();

        if ($this->savepointName !== null) {
            $this->connection->execute("ROLLBACK TO SAVEPOINT {$this->savepointName}");
        } else {
            $this->connection->execute('ROLLBACK');
        }

        $this->rolledBack = true;
        $this->events?->transactionRolledBack($this->connection, $this->savepointName, 'rollback');
    }

    public function isActive(): bool
    {
        return ! $this->committed && ! $this->rolledBack;
    }

    private function assertActive(): void
    {
        if (! $this->isActive()) {
            throw new \LogicException('The transaction is no longer active.');
        }
    }
}
