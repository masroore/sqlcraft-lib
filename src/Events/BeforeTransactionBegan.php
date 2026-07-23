<?php

declare(strict_types=1);

namespace SQLCraft\Events;

use SQLCraft\Contracts\Connection\ConnectionInterface;

final class BeforeTransactionBegan extends InterceptionEvent
{
    public function __construct(
        public readonly ConnectionInterface $connection,
        public readonly string $isolationLevel,
    ) {
    }
}
