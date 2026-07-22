<?php

declare(strict_types=1);

namespace SQLCraft\Connection;

use SQLCraft\Contracts\Connection\PreparedStatementInterface;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\DTO\ExecutionResult;
use SQLCraft\Exceptions\ConnectionClosedException;

/** @internal */
final class PdoPreparedStatement implements PreparedStatementInterface
{
    private bool $closed = false;

    public function __construct(
        private readonly PdoConnection $connection,
        private readonly string $sql,
    ) {}

    /** @param array<string|int, mixed> $params */
    #[\Override]
    public function execute(array $params = []): ExecutionResult
    {
        $this->assertOpen();

        return $this->connection->executePrepared($this->sql, $params);
    }

    /** @param array<string|int, mixed> $params */
    #[\Override]
    public function query(array $params = []): ResultInterface
    {
        $this->assertOpen();

        return $this->connection->queryPrepared($this->sql, $params);
    }

    #[\Override]
    public function close(): void
    {
        $this->closed = true;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new ConnectionClosedException('The prepared statement is closed.');
        }
    }
}
