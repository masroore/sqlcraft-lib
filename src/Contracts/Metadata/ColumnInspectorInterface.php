<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Metadata;

use SQLCraft\Collections\ColumnCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

interface ColumnInspectorInterface
{
    public function getColumns(ConnectionInterface $conn, QualifiedName $table): ColumnCollection;

    /** @return array<string, ColumnCollection> */
    public function getAllColumns(ConnectionInterface $conn, string $database, ?string $schema = null): array;

    public function getColumn(ConnectionInterface $conn, QualifiedName $table, Identifier $column): ColumnMeta;
}
