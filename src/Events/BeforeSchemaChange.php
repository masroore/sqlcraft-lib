<?php

declare(strict_types=1);

namespace SQLCraft\Events;

use SQLCraft\Contracts\Connection\ConnectionInterface;

final class BeforeSchemaChange extends InterceptionEvent
{
    public function __construct(
        public readonly ConnectionInterface $connection,
        public readonly string $objectType,
        public readonly string $objectName,
        public readonly string $operation,
        public readonly string $sql,
    ) {
    }
}
