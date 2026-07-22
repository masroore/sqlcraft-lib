<?php

declare(strict_types=1);

namespace SQLCraft\Events;

use SQLCraft\Contracts\Connection\ConnectionInterface;

final readonly class TransactionBeganEvent extends ObservabilityEvent
{
    public function __construct(
        public ConnectionInterface $connection,
        public string $isolationLevel,
        public ?string $savepoint,
    ) {}
}
