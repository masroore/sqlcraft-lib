<?php

declare(strict_types=1);

namespace SQLCraft\Events;

use SQLCraft\Contracts\Connection\ConnectionInterface;

final readonly class ExportWarningEvent extends ObservabilityEvent
{
    /** @param list<string> $tables */
    public function __construct(
        public ConnectionInterface $connection,
        public string $message,
        public array $tables,
    ) {
    }
}
