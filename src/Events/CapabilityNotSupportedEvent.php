<?php

declare(strict_types=1);

namespace SQLCraft\Events;

final readonly class CapabilityNotSupportedEvent extends ObservabilityEvent
{
    public function __construct(
        public string $capability,
        public string $platformName,
        public string $version,
    ) {
    }
}
