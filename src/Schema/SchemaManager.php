<?php

declare(strict_types=1);

namespace SQLCraft\Schema;

use SQLCraft\Collections\CharsetCollection;
use SQLCraft\Collections\CheckConstraintCollection;
use SQLCraft\Collections\CollationCollection;
use SQLCraft\Collections\ColumnCollection;
use SQLCraft\Collections\DatabaseCollection;
use SQLCraft\Collections\ForeignKeyCollection;
use SQLCraft\Collections\IndexCollection;
use SQLCraft\Collections\LazyCollection;
use SQLCraft\Collections\PartitionCollection;
use SQLCraft\Collections\PrivilegeCollection;
use SQLCraft\Collections\ProcessCollection;
use SQLCraft\Collections\QualifiedNameCollection;
use SQLCraft\Collections\RoutineCollection;
use SQLCraft\Collections\SchemaCollection;
use SQLCraft\Collections\SequenceCollection;
use SQLCraft\Collections\TableCollection;
use SQLCraft\Collections\TriggerCollection;
use SQLCraft\Collections\TypeCollection;
use SQLCraft\Collections\UserCollection;
use SQLCraft\Collections\ViewCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Events\SchemaEventDispatcherInterface;
use SQLCraft\Contracts\Metadata\CheckConstraintInspectorInterface;
use SQLCraft\Contracts\Metadata\ColumnInspectorInterface;
use SQLCraft\Contracts\Metadata\DatabaseInspectorInterface;
use SQLCraft\Contracts\Metadata\ForeignKeyInspectorInterface;
use SQLCraft\Contracts\Metadata\IndexInspectorInterface;
use SQLCraft\Contracts\Metadata\MetadataCacheInterface;
use SQLCraft\Contracts\Metadata\PrivilegeInspectorInterface;
use SQLCraft\Contracts\Metadata\RoutineInspectorInterface;
use SQLCraft\Contracts\Metadata\SequenceInspectorInterface;
use SQLCraft\Contracts\Metadata\ServerInspectorInterface;
use SQLCraft\Contracts\Metadata\TableInspectorInterface;
use SQLCraft\Contracts\Metadata\TriggerInspectorInterface;
use SQLCraft\Contracts\Metadata\UserInspectorInterface;
use SQLCraft\Contracts\Metadata\ViewInspectorInterface;
use SQLCraft\Contracts\Schema\SchemaManagerInterface;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\RoutineMeta;
use SQLCraft\DTO\ServerInfo;
use SQLCraft\DTO\TableStatus;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final class SchemaManager implements SchemaManagerInterface
{
    public function __construct(
        private readonly ServerInspectorInterface $serverInspector,
        private readonly DatabaseInspectorInterface $databaseInspector,
        private readonly TableInspectorInterface $tableInspector,
        private readonly ColumnInspectorInterface $columnInspector,
        private readonly IndexInspectorInterface $indexInspector,
        private readonly ForeignKeyInspectorInterface $foreignKeyInspector,
        private readonly ViewInspectorInterface $viewInspector,
        private readonly RoutineInspectorInterface $routineInspector,
        private readonly TriggerInspectorInterface $triggerInspector,
        private readonly SequenceInspectorInterface $sequenceInspector,
        private readonly CheckConstraintInspectorInterface $checkConstraintInspector,
        private readonly UserInspectorInterface $userInspector,
        private readonly ?MetadataCacheInterface $cache = null,
        private readonly ?SchemaEventDispatcherInterface $events = null,
        private readonly ?PrivilegeInspectorInterface $privilegeInspector = null,
    ) {}

    /** @return array<string, mixed> */
    #[\Override]
    public function compare(mixed $expected, mixed $actual): array
    {
        return $expected === $actual ? [] : ['expected' => $expected, 'actual' => $actual];
    }

    /** @param array<string, mixed> $diff */
    #[\Override]
    public function describeDiff(array $diff): string
    {
        return $diff === [] ? '' : json_encode($diff, JSON_THROW_ON_ERROR);
    }

    public function lazyTables(ConnectionInterface $conn, ?string $schema = null): LazyCollection
    {
        return new LazyCollection(fn (): iterable => $this->getTables($conn, $schema));
    }

    public function getPrivileges(ConnectionInterface $conn, ?string $user = null, ?QualifiedName $object = null): PrivilegeCollection
    {
        if (! $this->privilegeInspector instanceof PrivilegeInspectorInterface) {
            return new PrivilegeCollection([]);
        }

        return $this->privilegeInspector->getPrivileges($conn, $user, $object);
    }

    public function getServerInfo(ConnectionInterface $conn): ServerInfo
    {
        return $this->cached($conn, 'server-info', fn (): ServerInfo => $this->serverInspector->getServerInfo($conn));
    }

    public function getDatabases(ConnectionInterface $conn): DatabaseCollection
    {
        return $this->cached($conn, 'databases', fn (): DatabaseCollection => $this->serverInspector->getDatabases($conn));
    }

    public function getSchemas(ConnectionInterface $conn): SchemaCollection
    {
        return $this->cached($conn, 'schemas', fn (): SchemaCollection => $this->databaseInspector->getSchemas($conn));
    }

    public function getSequences(ConnectionInterface $conn, ?string $schema = null): SequenceCollection
    {
        return $this->cached($conn, 'sequences', fn (): SequenceCollection => $this->sequenceInspector->getSequences($conn, $schema));
    }

    public function getTypes(ConnectionInterface $conn, ?string $schema = null): TypeCollection
    {
        return $this->cached($conn, 'types', fn (): TypeCollection => $this->databaseInspector->getTypes($conn, $schema));
    }

    /** @return array<string, string> */
    public function getVariables(ConnectionInterface $conn): array
    {
        return $this->cached($conn, 'variables', fn (): array => $this->serverInspector->getVariables($conn));
    }

    /** @return array<string, string> */
    public function getStatus(ConnectionInterface $conn): array
    {
        return $this->cached($conn, 'status', fn (): array => $this->serverInspector->getStatus($conn));
    }

    public function getProcessList(ConnectionInterface $conn): ProcessCollection
    {
        return $this->cached($conn, 'process-list', fn (): ProcessCollection => $this->serverInspector->getProcessList($conn));
    }

    public function getCharsets(ConnectionInterface $conn): CharsetCollection
    {
        return $this->cached($conn, 'charsets', fn (): CharsetCollection => $this->serverInspector->getCharsets($conn));
    }

    public function getCollations(ConnectionInterface $conn, ?string $charset = null): CollationCollection
    {
        return $this->cached($conn, 'collations:'.($charset ?? ''), fn (): CollationCollection => $this->serverInspector->getCollations($conn, $charset));
    }

    public function getTables(ConnectionInterface $conn, ?string $schema = null): TableCollection
    {
        return $this->cached($conn, 'tables:'.($schema ?? ''), fn (): TableCollection => $this->tableInspector->getTables($conn, $schema));
    }

    /** @return \Generator<string, TableStatus> */
    public function streamTables(ConnectionInterface $conn, ?string $schema = null): \Generator
    {
        yield from $this->tableInspector->streamTables($conn, $schema);
    }

    public function getTableStatus(ConnectionInterface $conn, QualifiedName $table): TableStatus
    {
        return $this->cached($conn, 'table-status:'.$table->object->name, fn (): TableStatus => $this->tableInspector->getTableStatus($conn, $table));
    }

    public function getParentTables(ConnectionInterface $conn, QualifiedName $table): QualifiedNameCollection
    {
        return $this->cached($conn, 'parents:'.$table->object->name, fn (): QualifiedNameCollection => $this->tableInspector->getParentTables($conn, $table));
    }

    public function getPartitions(ConnectionInterface $conn, QualifiedName $table): PartitionCollection
    {
        return $this->cached($conn, 'partitions:'.$table->object->name, fn (): PartitionCollection => $this->tableInspector->getPartitions($conn, $table));
    }

    public function getColumns(ConnectionInterface $conn, QualifiedName $table): ColumnCollection
    {
        return $this->cached($conn, 'columns:'.$table->object->name, fn (): ColumnCollection => $this->columnInspector->getColumns($conn, $table));
    }

    /** @return array<string, ColumnCollection> */
    public function getAllColumns(ConnectionInterface $conn, string $database, ?string $schema = null): array
    {
        return $this->cached($conn, 'columns-all:'.($schema ?? ''), fn (): array => $this->columnInspector->getAllColumns($conn, $database, $schema));
    }

    public function getColumn(ConnectionInterface $conn, QualifiedName $table, Identifier $column): ColumnMeta
    {
        return $this->cached($conn, 'column:'.$table->object->name.':'.$column->name, fn (): ColumnMeta => $this->columnInspector->getColumn($conn, $table, $column));
    }

    public function getIndexes(ConnectionInterface $conn, QualifiedName $table): IndexCollection
    {
        return $this->cached($conn, 'indexes:'.$table->object->name, fn (): IndexCollection => $this->indexInspector->getIndexes($conn, $table));
    }

    public function getForeignKeys(ConnectionInterface $conn, QualifiedName $table): ForeignKeyCollection
    {
        return $this->cached($conn, 'foreign-keys:'.$table->object->name, fn (): ForeignKeyCollection => $this->foreignKeyInspector->getForeignKeys($conn, $table));
    }

    public function getReferencingKeys(ConnectionInterface $conn, QualifiedName $table): ForeignKeyCollection
    {
        return $this->cached($conn, 'referencing-keys:'.$table->object->name, fn (): ForeignKeyCollection => $this->foreignKeyInspector->getReferencingKeys($conn, $table));
    }

    public function getTriggers(ConnectionInterface $conn, QualifiedName $table): TriggerCollection
    {
        return $this->cached($conn, 'triggers:'.$table->object->name, fn (): TriggerCollection => $this->triggerInspector->getTriggers($conn, $table));
    }

    public function getViews(ConnectionInterface $conn, ?string $schema = null): ViewCollection
    {
        return $this->cached($conn, 'views:'.($schema ?? ''), fn (): ViewCollection => $this->viewInspector->getViews($conn, $schema));
    }

    public function getViewDefinition(ConnectionInterface $conn, QualifiedName $view): string
    {
        return $this->cached($conn, 'view-definition:'.$view->object->name, fn (): string => $this->viewInspector->getViewDefinition($conn, $view));
    }

    public function getMaterializedViews(ConnectionInterface $conn, ?string $schema = null): ViewCollection
    {
        return $this->cached($conn, 'materialized-views:'.($schema ?? ''), fn (): ViewCollection => $this->viewInspector->getMaterializedViews($conn, $schema));
    }

    public function getFunctions(ConnectionInterface $conn, ?string $schema = null): RoutineCollection
    {
        return $this->cached($conn, 'functions:'.($schema ?? ''), fn (): RoutineCollection => $this->routineInspector->getFunctions($conn, $schema));
    }

    public function getProcedures(ConnectionInterface $conn, ?string $schema = null): RoutineCollection
    {
        return $this->cached($conn, 'procedures:'.($schema ?? ''), fn (): RoutineCollection => $this->routineInspector->getProcedures($conn, $schema));
    }

    public function getRoutineDetail(ConnectionInterface $conn, QualifiedName $routine): RoutineMeta
    {
        return $this->cached($conn, 'routine-detail:'.$routine->object->name, fn (): RoutineMeta => $this->routineInspector->getRoutineDetail($conn, $routine));
    }

    public function getCheckConstraints(ConnectionInterface $conn, QualifiedName $table): CheckConstraintCollection
    {
        return $this->cached($conn, 'checks:'.$table->object->name, fn (): CheckConstraintCollection => $this->checkConstraintInspector->getCheckConstraints($conn, $table));
    }

    public function getUsers(ConnectionInterface $conn): UserCollection
    {
        return $this->cached($conn, 'users', fn (): UserCollection => $this->userInspector->getUsers($conn));
    }

    public function describeTable(ConnectionInterface $conn, QualifiedName $table): TableStructure
    {
        return $this->cached($conn, 'table-structure:'.$table->object->name, fn (): TableStructure => new TableStructure(
            status: $this->getTableStatus($conn, $table),
            columns: $this->getColumns($conn, $table),
            indexes: $this->getIndexes($conn, $table),
            foreignKeys: $this->getForeignKeys($conn, $table),
            triggers: $this->getTriggers($conn, $table),
        ));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $loader
     * @return T
     */
    private function cached(ConnectionInterface $conn, string $method, callable $loader): mixed
    {
        $startedAt = hrtime(true);
        $result = $this->cache instanceof MetadataCacheInterface
            ? $this->cache->remember($this->cacheKey($conn, $method), $loader)
            : $loader();
        $this->events?->metadataFetched($conn, $method, $method, (hrtime(true) - $startedAt) / 1_000_000);

        return $result;
    }

    private function cacheKey(ConnectionInterface $conn, string $method): string
    {
        return implode('/', [
            $conn->getPlatformName(),
            $conn->getDatabaseName() ?? '',
            $method,
        ]);
    }
}
