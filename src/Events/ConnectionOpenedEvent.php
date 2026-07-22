<?php

declare(strict_types=1);

namespace SQLCraft\Events;

final readonly class ConnectionOpenedEvent extends ObservabilityEvent
{
    public function __construct(
        public string $name,
        public string $driver,
        public ?string $host,
        public ?string $database,
        public float $elapsedMs,
    ) {}
}
