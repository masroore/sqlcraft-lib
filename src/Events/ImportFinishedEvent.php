<?php

declare(strict_types=1);

namespace SQLCraft\Events;

use SQLCraft\Contracts\Connection\ConnectionInterface;

final readonly class ImportFinishedEvent extends ObservabilityEvent
{
    /** @param list<object> $errors */
    public function __construct(
        public ConnectionInterface $connection,
        public int $statementsExecuted,
        public array $errors,
        public float $elapsedMs,
    ) {}
}
