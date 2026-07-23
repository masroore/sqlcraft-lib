<?php

declare(strict_types=1);

namespace SQLCraft\Events;

use SQLCraft\Contracts\Connection\ConnectionInterface;

final readonly class SlowQueryDetectedEvent extends ObservabilityEvent
{
    /** @param array<string|int, mixed> $params */
    public function __construct(
        public ConnectionInterface $connection,
        public string $sql,
        public array $params,
        public float $elapsedMs,
        public int $thresholdMs,
    ) {
    }
}
