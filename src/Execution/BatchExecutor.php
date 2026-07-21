<?php

declare(strict_types=1);

namespace SQLCraft\Execution;

use InvalidArgumentException;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Execution\BatchExecutorInterface;
use SQLCraft\Contracts\Execution\BatchStatementResult;
use SQLCraft\Contracts\Execution\StatementBatch;
use SQLCraft\Contracts\Execution\QueryExecutorInterface;

final readonly class BatchExecutor implements BatchExecutorInterface
{
    public function __construct(
        private QueryExecutorInterface $executor,
        private int $maximumStatements = 1000,
    ) {
        if ($maximumStatements < 1) {
            throw new InvalidArgumentException('Maximum batch statements must be >= 1.');
        }
    }

    /** @return \Generator<int, BatchStatementResult> */
    #[\Override]
    public function executeBatch(ConnectionInterface $connection, StatementBatch $batch, bool $stopOnError = true): \Generator
    {
        if (count($batch->statements) > $this->maximumStatements) {
            throw new InvalidArgumentException(sprintf('Batch cannot contain more than %d statements.', $this->maximumStatements));
        }

        foreach ($batch->statements as $index => $sql) {
            $startedAt = hrtime(true);
            try {
                if ($this->isQuery($sql)) {
                    $rows = $this->executor->query($connection, $sql);
                    yield new BatchStatementResult($index, $sql, null, $rows, $this->elapsedMs($startedAt), null);
                    continue;
                }

                $result = $this->executor->execute($connection, $sql);
                yield new BatchStatementResult($index, $sql, $result, null, $this->elapsedMs($startedAt), null);
            } catch (\Throwable $error) {
                if ($stopOnError) {
                    throw $error;
                }

                yield new BatchStatementResult($index, $sql, null, null, $this->elapsedMs($startedAt), $error);
            }
        }
    }

    private function isQuery(string $sql): bool
    {
        return preg_match('/^\\s*(?:SELECT|WITH|SHOW|DESCRIBE|DESC|EXPLAIN|PRAGMA)\\b/i', $sql) === 1;
    }

    private function elapsedMs(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1_000_000;
    }
}
