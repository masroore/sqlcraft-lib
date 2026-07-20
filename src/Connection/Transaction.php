<?php

declare(strict_types=1);

namespace SQLCraft\Connection;

use SQLCraft\Contracts\Connection\ConnectionInterface;

final class Transaction
{
    private bool $committed = false;
    private bool $rolledBack = false;

    public function __construct(
        private readonly ConnectionInterface $connection,
        public readonly string $isolationLevel = '',
        public readonly ?string $savepointName = null,
    ) {
    }

    public function commit(): void
    {
        if ($this->savepointName !== null) {
            $this->connection->execute("RELEASE SAVEPOINT {$this->savepointName}");
        } else {
            $this->connection->execute('COMMIT');
        }

        $this->committed = true;
    }

    public function rollback(): void
    {
        if ($this->savepointName !== null) {
            $this->connection->execute("ROLLBACK TO SAVEPOINT {$this->savepointName}");
        } else {
            $this->connection->execute('ROLLBACK');
        }

        $this->rolledBack = true;
    }

    public function isActive(): bool
    {
        return !$this->committed && !$this->rolledBack;
    }
}
