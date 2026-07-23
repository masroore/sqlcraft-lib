<?php

declare(strict_types=1);

namespace SQLCraft\Events;

use SQLCraft\Contracts\Connection\ConnectionInterface;

final readonly class ImportProgressEvent extends ObservabilityEvent
{
    public function __construct(
        public ConnectionInterface $connection,
        public int $bytesProcessed,
        public int $statementsExecuted,
        public float $elapsedMs,
    ) {
    }
}
