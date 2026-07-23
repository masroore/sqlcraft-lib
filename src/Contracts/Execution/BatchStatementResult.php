<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Execution;

use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\DTO\ExecutionResult;

final readonly class BatchStatementResult
{
    public function __construct(
        public int $index,
        public string $sql,
        public ?ExecutionResult $result,
        public ?ResultInterface $rows,
        public float $elapsedMs,
        public ?\Throwable $error,
    ) {
    }
}
