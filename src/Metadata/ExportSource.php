<?php

declare(strict_types=1);

namespace SQLCraft\Metadata;

use SQLCraft\Collections\ColumnCollection;
use SQLCraft\Collections\DatabaseCollection;
use SQLCraft\Collections\TableCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Export\ExportSourceInterface;
use SQLCraft\Contracts\Export\ForeignKeyExportSourceInterface;
use SQLCraft\Contracts\Metadata\ColumnInspectorInterface;
use SQLCraft\Contracts\Metadata\ForeignKeyInspectorInterface;
use SQLCraft\Contracts\Metadata\RoutineInspectorInterface;
use SQLCraft\Contracts\Metadata\ServerInspectorInterface;
use SQLCraft\Contracts\Metadata\TableInspectorInterface;
use SQLCraft\Contracts\Metadata\TriggerInspectorInterface;
use SQLCraft\DTO\ForeignKeyMeta;
use SQLCraft\DTO\TableStatus;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

/** @internal */
final readonly class ExportSource implements ExportSourceInterface, ForeignKeyExportSourceInterface
{
    public function __construct(
        private TableInspectorInterface $tables,
        private ColumnInspectorInterface $columns,
        private ?TriggerInspectorInterface $triggers = null,
        private ?RoutineInspectorInterface $routines = null,
        private ?ServerInspectorInterface $server = null,
        private ?ForeignKeyInspectorInterface $foreignKeys = null,
    ) {}

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

    /** @return iterable<ForeignKeyMeta> */
    #[\Override]
    public function getForeignKeys(ConnectionInterface $connection, string $table, ?string $schema = null): iterable
    {
        if (! $this->foreignKeys instanceof ForeignKeyInspectorInterface) {
            return [];
        }

        return $this->foreignKeys->getForeignKeys($connection, $this->qualifiedName($connection, $table, $schema));
    }

    #[\Override]
    public function getTableDdl(ConnectionInterface $connection, string $table, ?string $schema = null): array
    {
        $columns = $this->getColumns($connection, $table, $schema);
        $definitions = [];
        foreach ($columns as $column) {
            $definition = $connection->quoteIdentifier($column->name).' '.$column->dataType->name;
            if (! $column->nullable) {
                $definition .= ' NOT NULL';
            }
            if ($column->primary) {
                $definition .= ' PRIMARY KEY';
            }
            $definitions[] = $definition;
        }

        $qualified = $schema === null
            ? $connection->quoteIdentifier($table)
            : $connection->quoteIdentifier($schema).'.'.$connection->quoteIdentifier($table);

        return ['CREATE TABLE '.$qualified.' ('.implode(', ', $definitions).')'];
    }

    #[\Override]
    public function getDatabases(ConnectionInterface $connection): DatabaseCollection
    {
        if (! $this->server instanceof ServerInspectorInterface) {
            throw new \LogicException('Database introspection is not configured for this export source.');
        }

        return $this->server->getDatabases($connection);
    }

    /** @return list<string> */
    #[\Override]
    public function getTriggerDdl(ConnectionInterface $connection, string $table, ?string $schema = null): array
    {
        if (! $this->triggers instanceof TriggerInspectorInterface) {
            return [];
        }

        $definitions = [];
        foreach ($this->triggers->getTriggers($connection, $this->qualifiedName($connection, $table, $schema)) as $trigger) {
            $sql = 'CREATE TRIGGER '.$connection->quoteIdentifier($trigger->name)
                .' '.$trigger->timing->value.' '.$trigger->event->value
                .' ON '.$connection->quoteIdentifier($table)
                .' FOR EACH ROW '.$trigger->body;
            $definitions[] = $sql;
        }

        return $definitions;
    }

    /** @return list<string> */
    #[\Override]
    public function getRoutineDdl(ConnectionInterface $connection, ?string $schema = null): array
    {
        if (! $this->routines instanceof RoutineInspectorInterface) {
            return [];
        }

        $definitions = [];
        foreach ([$this->routines->getFunctions($connection, $schema), $this->routines->getProcedures($connection, $schema)] as $routines) {
            foreach ($routines as $routine) {
                $definitions[] = 'CREATE '.$routine->type.' '.$connection->quoteIdentifier($routine->name).' '.$routine->body;
            }
        }

        return $definitions;
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
