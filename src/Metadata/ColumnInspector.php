<?php

declare(strict_types=1);

namespace SQLCraft\Metadata;

use InvalidArgumentException;
use SQLCraft\Collections\ColumnCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Metadata\ColumnInspectorInterface;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\Exceptions\ObjectNotFoundException;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

/** @internal */
final class ColumnInspector implements ColumnInspectorInterface
{
    public function __construct(private readonly MetadataFactoryInterface $factory)
    {
    }

    #[\Override]
    public function getColumns(ConnectionInterface $conn, QualifiedName $table): ColumnCollection
    {
        /** @var list<array<string, bool|float|int|string|null>> $rows */
        $rows = $conn->query($conn->getPlatform()->getColumnsSql($table))->fetchAll();
        $columns = [];

        foreach ($rows as $row) {
            $column = $this->factory->createColumnMeta($row);
            $columns[$column->name] = $column;
        }

        return new ColumnCollection($columns);
    }

    /**
     * @return array<string, ColumnCollection>
     */
    #[\Override]
    public function getAllColumns(ConnectionInterface $conn, string $database, ?string $schema = null): array
    {
        /** @var list<array<string, bool|float|int|string|null>> $rows */
        $rows = $conn->query($conn->getPlatform()->getAllColumnsSql($database, $schema))->fetchAll();
        $columns = [];

        foreach ($rows as $row) {
            $tableName = $this->tableName($row);
            $column = $this->factory->createColumnMeta($row);
            $columns[$tableName] ??= [];
            $columns[$tableName][$column->name] = $column;
        }

        return array_map(
            static fn (array $tableColumns): ColumnCollection => new ColumnCollection($tableColumns),
            $columns,
        );
    }

    #[\Override]
    public function getColumn(ConnectionInterface $conn, QualifiedName $table, Identifier $column): ColumnMeta
    {
        $columns = $this->getColumns($conn, $table);
        foreach ($columns as $metadata) {
            if ($metadata->name === $column->name) {
                return $metadata;
            }
        }

        throw new ObjectNotFoundException(
            sprintf('Column %s does not exist on table %s.', $column->name, $table->object->name),
            $table->object->name . '.' . $column->name,
        );
    }

    /** @param array<string, bool|float|int|string|null> $row */
    private function tableName(array $row): string
    {
        foreach (['table_name', 'tablename', 'table'] as $key) {
            $value = $row[$key] ?? null;
            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        throw new InvalidArgumentException('Batch column metadata is missing its table name.');
    }
}
