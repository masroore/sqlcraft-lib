<?php

declare(strict_types=1);

namespace SQLCraft\Execution;

use InvalidArgumentException;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\Contracts\Execution\QueryExecutorInterface;
use SQLCraft\DTO\ExecutionResult;

final readonly class QueryExecutor implements QueryExecutorInterface
{
    /** @param array<string|int, mixed> $params */
    #[\Override]
    public function execute(ConnectionInterface $connection, string $sql, array $params = []): ExecutionResult
    {
        return $connection->execute($sql, $params);
    }

    /** @param array<string|int, mixed> $params */
    #[\Override]
    public function query(ConnectionInterface $connection, string $sql, array $params = [], bool $buffered = false): ResultInterface
    {
        return $connection->query($sql, $params, streaming: !$buffered);
    }

    /** @param array<string|int, mixed> $params */
    #[\Override]
    public function executeDdl(ConnectionInterface $connection, string $sql, array $params = []): void
    {
        $connection->execute($sql, $params);
    }

    /** @param array<string|int, mixed> $params */
    #[\Override]
    public function queryWithTimeout(ConnectionInterface $connection, string $sql, array $params = [], int $timeoutMs = 0): ?ResultInterface
    {
        if ($timeoutMs < 0) {
            throw new InvalidArgumentException('Query timeout must be zero or greater.');
        }

        if ($timeoutMs === 0) {
            return $this->query($connection, $sql, $params);
        }

        $wrapped = $connection->getPlatform()->wrapWithTimeout($sql, $timeoutMs);
        if ($wrapped === null) {
            return null;
        }

        return $this->query($connection, $wrapped, $params);
    }
}
