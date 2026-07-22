<?php

declare(strict_types=1);

namespace SQLCraft\Events;

use SQLCraft\Contracts\Connection\ConnectionInterface;

final readonly class QueryFailedEvent extends ObservabilityEvent
{
    /** @param array<string|int, mixed> $params */
    public function __construct(
        public ConnectionInterface $connection,
        public string $sql,
        public array $params,
        public \Throwable $exception,
        public float $elapsedMs,
    ) {}
}
