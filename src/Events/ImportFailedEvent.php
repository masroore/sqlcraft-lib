<?php

declare(strict_types=1);

namespace SQLCraft\Events;

use SQLCraft\Contracts\Connection\ConnectionInterface;

final readonly class ImportFailedEvent extends ObservabilityEvent
{
    public function __construct(
        public ConnectionInterface $connection,
        public \Throwable $exception,
        public ?string $lastSql,
        public float $elapsedMs,
    ) {}
}
