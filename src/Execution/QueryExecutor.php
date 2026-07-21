<?php

declare(strict_types=1);

namespace SQLCraft\Execution;

use InvalidArgumentException;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\Contracts\Execution\QueryExecutorInterface;
use SQLCraft\Contracts\Execution\QueryHistoryEntry;
use SQLCraft\Contracts\Execution\QueryHistoryInterface;
use SQLCraft\DTO\ExecutionResult;

final readonly class QueryExecutor implements QueryExecutorInterface
{
    public function __construct(private ?QueryHistoryInterface $history = null)
    {
    }

    /** @param array<string|int, mixed> $params */
    #[\Override]
    public function execute(ConnectionInterface $connection, string $sql, array $params = []): ExecutionResult
    {
        $startedAt = hrtime(true);
        try {
            $result = $connection->execute($sql, $params);
            $this->record($connection, $sql, $startedAt, true);

            return $result;
        } catch (\Throwable $error) {
            $this->record($connection, $sql, $startedAt, false, $error->getMessage());
            throw $error;
        }
    }

    /** @param array<string|int, mixed> $params */
    #[\Override]
    public function query(ConnectionInterface $connection, string $sql, array $params = [], bool $buffered = false): ResultInterface
    {
        $startedAt = hrtime(true);
        try {
            $result = $connection->query($sql, $params, streaming: !$buffered);
            $this->record($connection, $sql, $startedAt, true);

            return $result;
        } catch (\Throwable $error) {
            $this->record($connection, $sql, $startedAt, false, $error->getMessage());
            throw $error;
        }
    }

    /** @param array<string|int, mixed> $params */
    #[\Override]
    public function executeDdl(ConnectionInterface $connection, string $sql, array $params = []): void
    {
        $startedAt = hrtime(true);
        try {
            $connection->execute($sql, $params);
            $this->record($connection, $sql, $startedAt, true);
        } catch (\Throwable $error) {
            $this->record($connection, $sql, $startedAt, false, $error->getMessage());
            throw $error;
        }
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

    private function record(ConnectionInterface $connection, string $sql, int $startedAt, bool $success, ?string $errorMessage = null): void
    {
        if (!$this->history instanceof QueryHistoryInterface) {
            return;
        }

        $this->history->record(new QueryHistoryEntry(
            database: $connection->getDatabaseName() ?? '',
            sql: $sql,
            elapsedMs: (hrtime(true) - $startedAt) / 1_000_000,
            executedAt: new \DateTimeImmutable(),
            success: $success,
            errorMessage: $errorMessage,
        ));
    }
}
