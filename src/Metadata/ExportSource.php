<?php

declare(strict_types=1);

namespace SQLCraft\Metadata;

use SQLCraft\Collections\ColumnCollection;
use SQLCraft\Collections\TableCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Export\ExportSourceInterface;
use SQLCraft\Contracts\Metadata\ColumnInspectorInterface;
use SQLCraft\Contracts\Metadata\TableInspectorInterface;
use SQLCraft\DTO\TableStatus;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

/** @internal */
final readonly class ExportSource implements ExportSourceInterface
{
    public function __construct(
        private TableInspectorInterface $tables,
        private ColumnInspectorInterface $columns,
    ) {
    }

    #[\Override]
    public function getTables(ConnectionInterface $connection, ?string $schema = null): TableCollection
    {
        return $this->tables->getTables($connection, $schema);
    }

    #[\Override]
    public function getTableStatus(ConnectionInterface $connection, string $table, ?string $schema = null): TableStatus
    {
        return $this->tables->getTableStatus($connection, $this->qualifiedName($connection, $table, $schema));
    }

    #[\Override]
    public function getColumns(ConnectionInterface $connection, string $table, ?string $schema = null): ColumnCollection
    {
        return $this->columns->getColumns($connection, $this->qualifiedName($connection, $table, $schema));
    }

    #[\Override]
    public function getTableDdl(ConnectionInterface $connection, string $table, ?string $schema = null): array
    {
        $columns = $this->getColumns($connection, $table, $schema);
        $definitions = [];
        foreach ($columns as $column) {
            $definition = $connection->quoteIdentifier($column->name) . ' ' . $column->dataType->name;
            if (!$column->nullable) {
                $definition .= ' NOT NULL';
            }
            if ($column->primary) {
                $definition .= ' PRIMARY KEY';
            }
            $definitions[] = $definition;
        }

        $qualified = $schema === null
            ? $connection->quoteIdentifier($table)
            : $connection->quoteIdentifier($schema) . '.' . $connection->quoteIdentifier($table);

        return ['CREATE TABLE ' . $qualified . ' (' . implode(', ', $definitions) . ')'];
    }

    private function qualifiedName(ConnectionInterface $connection, string $table, ?string $schema): QualifiedName
    {
        $identifier = new Identifier($table);
        if (in_array($connection->getPlatformName(), ['mysql', 'mariadb'], true)) {
            $catalog = $schema ?? $connection->getDatabaseName();

            return new QualifiedName(
                object: $identifier,
                catalog: $catalog === null ? null : new Identifier($catalog),
            );
        }

        return new QualifiedName(
            object: $identifier,
            schema: $schema === null ? null : new Identifier($schema),
        );
    }
}
