<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Execution;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Execution\BatchStatementResult;
use SQLCraft\Contracts\Execution\StatementBatch;

interface BatchExecutorInterface
{
    /** @return \Generator<int, BatchStatementResult> */
    public function executeBatch(
        ConnectionInterface $connection,
        StatementBatch $batch,
        bool $stopOnError = true,
        int $timeoutMs = 0,
    ): \Generator;
}
