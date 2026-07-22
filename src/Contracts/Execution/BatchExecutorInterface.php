<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Execution;

use SQLCraft\Contracts\Connection\ConnectionInterface;

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
