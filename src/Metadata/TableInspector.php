<?php

declare(strict_types=1);

namespace SQLCraft\Metadata;

use InvalidArgumentException;
use SQLCraft\Collections\PartitionCollection;
use SQLCraft\Collections\QualifiedNameCollection;
use SQLCraft\Collections\TableCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Metadata\TableInspectorInterface;
use SQLCraft\DTO\TableStatus;
use SQLCraft\Exceptions\ObjectNotFoundException;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

/** @internal */
final class TableInspector implements TableInspectorInterface
{
    public function __construct(private readonly MetadataFactoryInterface $factory)
    {
    }

    #[\Override]
    public function getTables(ConnectionInterface $conn, ?string $schema = null): TableCollection
    {
        $rows = $conn->query($this->tablesSql($conn, $schema))->fetchAll();
        $tables = [];

        foreach ($rows as $row) {
            /** @var array<string, bool|float|int|string|null> $row */
            $status = $this->factory->createTableStatus($row);
            $tables[$status->name] = $status;
        }

        return new TableCollection($tables);
    }

    /** @return \Generator<string, TableStatus> */
    #[\Override]
    public function streamTables(ConnectionInterface $conn, ?string $schema = null): \Generator
    {
        $result = $conn->query($this->tablesSql($conn, $schema), [], true);

        foreach ($result as $row) {
            /** @var array<string, bool|float|int|string|null> $row */
            $status = $this->factory->createTableStatus($row);
            yield $status->name => $status;
        }
    }

    #[\Override]
    public function getTableStatus(ConnectionInterface $conn, QualifiedName $table): TableStatus
    {
        $row = $conn->query($conn->getPlatform()->getTableStatusSql($table))->fetchAssoc();
        if ($row === null) {
            throw new ObjectNotFoundException(
                sprintf('Table %s does not exist.', $table->object->name),
                $table->object->name,
            );
        }

        /** @var array<string, bool|float|int|string|null> $row */
        return $this->factory->createTableStatus($row);
    }

    #[\Override]
    public function getParentTables(ConnectionInterface $conn, QualifiedName $table): QualifiedNameCollection
    {
        $sql = $conn->getPlatform()->getParentTablesSql($table);
        if ($sql === '') {
            return new QualifiedNameCollection([]);
        }

        /** @var list<array<string, bool|float|int|string|null>> $rows */
        $rows = $conn->query($sql)->fetchAll();
        $parents = [];

        foreach ($rows as $row) {
            $parent = $this->qualifiedName($row);
            $parents[$parent->object->name] = $parent;
        }

        return new QualifiedNameCollection($parents);
    }

    #[\Override]
    public function getPartitions(ConnectionInterface $conn, QualifiedName $table): PartitionCollection
    {
        $sql = $conn->getPlatform()->getPartitionsSql($table);
        /** @var list<array<string, bool|float|int|string|null>> $rows */
        $rows = $conn->query($sql)->fetchAll();
        $partitions = [];

        foreach ($rows as $row) {
            $partition = $this->factory->createPartitionInfo($row);
            $partitions[$partition->name] = $partition;
        }

        return new PartitionCollection($partitions);
    }

    private function tablesSql(ConnectionInterface $conn, ?string $schema): string
    {
        $database = $conn->getDatabaseName();
        if ($database === null) {
            throw new InvalidArgumentException('A database name is required to inspect tables.');
        }

        return $conn->getPlatform()->getTablesSql($database, $schema);
    }

    /** @param array<string, bool|float|int|string|null> $row */
    private function qualifiedName(array $row): QualifiedName
    {
        $name = $this->stringValue($row, 'table_name', 'name');
        if ($name === null || $name === '') {
            throw new InvalidArgumentException('Parent table metadata is missing a table name.');
        }

        $schema = $this->stringValue($row, 'schema', 'table_schema', 'parent_schema');
        $catalog = $this->stringValue($row, 'catalog', 'table_catalog', 'parent_catalog');

        return new QualifiedName(
            object: new Identifier($name),
            schema: $schema === null || $schema === '' ? null : new Identifier($schema),
            catalog: $catalog === null || $catalog === '' ? null : new Identifier($catalog),
        );
    }

    /** @param array<string, bool|float|int|string|null> $row */
    private function stringValue(array $row, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                return (string) $row[$key];
            }
        }

        return null;
    }
}
