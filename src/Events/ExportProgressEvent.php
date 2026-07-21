<?php

declare(strict_types=1);

namespace SQLCraft\Events;

use SQLCraft\Contracts\Connection\ConnectionInterface;

final readonly class ExportProgressEvent
{
    public function __construct(
        public ConnectionInterface $connection,
        public int $tablesExported,
        public int $rowsExported,
        public float $elapsedMs,
    ) {
    }
}
