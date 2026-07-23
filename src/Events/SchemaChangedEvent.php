<?php

declare(strict_types=1);

namespace SQLCraft\Events;

use SQLCraft\Contracts\Connection\ConnectionInterface;

final readonly class SchemaChangedEvent extends ObservabilityEvent
{
    public function __construct(
        public ConnectionInterface $connection,
        public string $objectType,
        public string $objectName,
        public string $operation,
    ) {
    }
}
