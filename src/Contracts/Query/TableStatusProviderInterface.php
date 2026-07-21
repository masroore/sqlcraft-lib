<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Query;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\ValueObjects\QualifiedName;

interface TableStatusProviderInterface
{
    public function getApproximateRowCount(ConnectionInterface $connection, QualifiedName $table): ?int;
}
