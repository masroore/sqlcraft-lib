<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Export;

use SQLCraft\Collections\ColumnCollection;
use SQLCraft\Collections\TableCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\DTO\TableStatus;

interface ExportSourceInterface
{
    public function getTables(ConnectionInterface $connection, ?string $schema = null): TableCollection;

    public function getTableStatus(ConnectionInterface $connection, string $table, ?string $schema = null): TableStatus;

    public function getColumns(ConnectionInterface $connection, string $table, ?string $schema = null): ColumnCollection;
}
