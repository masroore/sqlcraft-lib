<?php

declare(strict_types=1);

namespace SQLCraft\Events;

use SQLCraft\Contracts\Connection\ConnectionInterface;

final readonly class ExportStartedEvent extends ObservabilityEvent
{
    /** @param list<string> $tables */
    public function __construct(
        public ConnectionInterface $connection,
        public object $target,
        public string $format,
        public array $tables,
    ) {
    }
}
