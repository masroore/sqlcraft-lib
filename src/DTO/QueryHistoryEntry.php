<?php

declare(strict_types=1);

namespace SQLCraft\DTO;

final readonly class QueryHistoryEntry
{
    public function __construct(
        public string $database,
        public string $sql,
        public float $elapsedMs,
        public \DateTimeImmutable $executedAt,
        public bool $success,
        public ?string $errorMessage,
    ) {
    }
}
