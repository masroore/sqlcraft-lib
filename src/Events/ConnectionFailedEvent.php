<?php

declare(strict_types=1);

namespace SQLCraft\Events;

final readonly class ConnectionFailedEvent extends ObservabilityEvent
{
    public function __construct(
        public string $name,
        public string $driver,
        public \Throwable $error,
    ) {}
}
