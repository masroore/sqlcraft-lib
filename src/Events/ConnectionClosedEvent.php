<?php

declare(strict_types=1);

namespace SQLCraft\Events;

final readonly class ConnectionClosedEvent extends ObservabilityEvent
{
    public function __construct(
        public string $name,
        public string $driver,
    ) {
    }
}
