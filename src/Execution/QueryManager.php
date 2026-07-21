<?php

declare(strict_types=1);

namespace SQLCraft\Execution;

use LogicException;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\Contracts\Execution\BatchExecutorInterface;
use SQLCraft\Contracts\Execution\QueryExecutorInterface;
use SQLCraft\Contracts\Execution\StatementBatch;
use SQLCraft\Contracts\Execution\StatementSplitterInterface;
use SQLCraft\DTO\ExecutionResult;

final readonly class QueryManager
{
    public function __construct(
        private QueryExecutorInterface $executor,
        private ?StatementSplitterInterface $splitter = null,
        private ?BatchExecutorInterface $batchExecutor = null,
    ) {
    }

    /** @param array<string|int, mixed> $params */
    public function execute(ConnectionInterface $connection, string $sql, array $params = []): ExecutionResult
    {
        return $this->executor->execute($connection, $sql, $params);
    }

    /** @param array<string|int, mixed> $params */
    public function query(ConnectionInterface $connection, string $sql, array $params = [], bool $buffered = false): ResultInterface
    {
        return $this->executor->query($connection, $sql, $params, $buffered);
    }

    /** @param array<string|int, mixed> $params */
    public function executeDdl(ConnectionInterface $connection, string $sql, array $params = []): void
    {
        $this->executor->executeDdl($connection, $sql, $params);
    }

    /** @param array<string|int, mixed> $params */
    public function queryWithTimeout(ConnectionInterface $connection, string $sql, array $params = [], int $timeoutMs = 0): ?ResultInterface
    {
        return $this->executor->queryWithTimeout($connection, $sql, $params, $timeoutMs);
    }

    public function split(string $sql, string $delimiter = ';'): StatementBatch
    {
        if (!$this->splitter instanceof StatementSplitterInterface) {
            throw new LogicException('No statement splitter configured.');
        }

        return $this->splitter->split($sql, $delimiter);
    }

    /** @return \Generator<int, \SQLCraft\Contracts\Execution\BatchStatementResult> */
    public function executeBatch(ConnectionInterface $connection, StatementBatch $batch, bool $stopOnError = true): \Generator
    {
        if (!$this->batchExecutor instanceof BatchExecutorInterface) {
            throw new LogicException('No batch executor configured.');
        }

        yield from $this->batchExecutor->executeBatch($connection, $batch, $stopOnError);
    }
}
