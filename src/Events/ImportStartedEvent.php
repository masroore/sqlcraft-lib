<?php

declare(strict_types=1);

namespace SQLCraft\Events;

use SQLCraft\Contracts\Connection\ConnectionInterface;

final readonly class ImportStartedEvent
{
    public function __construct(
        public ConnectionInterface $connection,
        public object $source,
        public ?int $estimatedBytes,
    ) {
    }
}
