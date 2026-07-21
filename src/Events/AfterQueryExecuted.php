<?php

declare(strict_types=1);

namespace SQLCraft\Events;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\DTO\ExecutionResult;

final readonly class AfterQueryExecuted extends ObservabilityEvent
{
    /** @param array<string|int, mixed> $params */
    public function __construct(
        public ConnectionInterface $connection,
        public string $sql,
        public array $params,
        public ExecutionResult $result,
        public float $elapsedMs,
    ) {
    }
}
