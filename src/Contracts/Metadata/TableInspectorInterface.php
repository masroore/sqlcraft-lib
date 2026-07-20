<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Metadata;

use SQLCraft\Collections\PartitionCollection;
use SQLCraft\Collections\QualifiedNameCollection;
use SQLCraft\Collections\TableCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\DTO\TableStatus;
use SQLCraft\ValueObjects\QualifiedName;

interface TableInspectorInterface
{
    public function getTables(ConnectionInterface $conn, ?string $schema = null): TableCollection;

    /** @return \Generator<string, TableStatus> */
    public function streamTables(ConnectionInterface $conn, ?string $schema = null): \Generator;

    public function getTableStatus(ConnectionInterface $conn, QualifiedName $table): TableStatus;

    public function getParentTables(ConnectionInterface $conn, QualifiedName $table): QualifiedNameCollection;

    public function getPartitions(ConnectionInterface $conn, QualifiedName $table): PartitionCollection;
}
