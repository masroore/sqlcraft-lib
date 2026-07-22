<?php

declare(strict_types=1);

namespace SQLCraft\Events;

use SQLCraft\Contracts\Connection\ConnectionInterface;

final readonly class ImportStartedEvent extends ObservabilityEvent
{
    public function __construct(
        public ConnectionInterface $connection,
        public object $source,
        public ?int $estimatedBytes,
        public string $format = 'sql',
    ) {}
}
