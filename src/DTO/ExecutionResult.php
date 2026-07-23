<?php

declare(strict_types=1);

namespace SQLCraft\DTO;

final readonly class ExecutionResult
{
    public function __construct(
        public int $affectedRows,
        public string|int $lastInsertId,
        public float $elapsedMs,
        public string $sql,
    ) {
    }
}
