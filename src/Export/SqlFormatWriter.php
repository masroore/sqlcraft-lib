<?php

declare(strict_types=1);

namespace SQLCraft\Export;

use InvalidArgumentException;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Export\FormatWriterInterface;
use SQLCraft\Contracts\Export\SinkInterface;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\TableStatus;

final class SqlFormatWriter implements FormatWriterInterface
{
    public function __construct(private readonly ConnectionInterface $connection)
    {
    }

    #[\Override]
    public function getFormatName(): string
    {
        return 'sql';
    }

    #[\Override]
    public function writeHeader(SinkInterface $sink, DumpOptions $options): void
    {
        $sink->write("-- SQLCraft dump\n\n");
    }

    #[\Override]
    public function writeTableHeader(SinkInterface $sink, TableStatus $table, DumpOptions $options): void
    {
        $kind = $table->isView ? 'View' : 'Table';
        $sink->write('-- ' . $kind . ': ' . $table->name . "\n");

        if ($options->tableStyle !== TableSectionStyle::DropCreate) {
            return;
        }

        $object = $this->quoteTable($table);
        $type = $table->isView ? 'VIEW' : 'TABLE';
        $sink->write('DROP ' . $type . ' IF EXISTS ' . $object . ";\n");
    }

    /** @param list<string> $ddlStatements */
    #[\Override]
    public function writeTableDdl(SinkInterface $sink, TableStatus $table, array $ddlStatements): void
    {
        foreach ($ddlStatements as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }

            $sink->write(rtrim($statement, ';') . ";\n");
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<ColumnMeta> $columns
     */
    #[\Override]
    public function writeRows(
        SinkInterface $sink,
        TableStatus $table,
        array $rows,
        array $columns,
        DumpOptions $options,
    ): void {
        if ($rows === [] || $columns === [] || $options->dataStyle === DataStyle::None) {
            return;
        }

        $columnNames = [];
        foreach ($columns as $column) {
            $columnNames[] = $this->connection->quoteIdentifier($column->name);
        }
        $values = [];
        foreach ($rows as $row) {
            $values[] = '(' . implode(', ', $this->renderRow($row, $columns)) . ')';
        }

        $sink->write(
            'INSERT INTO ' . $this->quoteTable($table)
            . ' (' . implode(', ', $columnNames) . ') VALUES '
            . implode(', ', $values) . ";\n",
        );
    }

    #[\Override]
    public function writeTableFooter(SinkInterface $sink, TableStatus $table): void
    {
        $sink->write("\n");
    }

    #[\Override]
    public function writeFooter(SinkInterface $sink, DumpOptions $options): void
    {
        $sink->write("-- End SQLCraft dump\n");
    }

    /**
     * @param array<string, mixed> $row
     * @param list<ColumnMeta> $columns
     * @return list<string>
     */
    private function renderRow(array $row, array $columns): array
    {
        $values = [];
        foreach ($columns as $column) {
            $values[] = $this->renderValue($row[$column->name] ?? null, $column);
        }

        return $values;
    }

    private function renderValue(mixed $value, ColumnMeta $column): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if ($this->isBinary($column)) {
            if (!is_string($value)) {
                throw new InvalidArgumentException('Binary column values must be strings.');
            }

            return $this->connection->getPlatform()->quoteBinary($value);
        }

        return $this->connection->quoteValue($value);
    }

    private function quoteTable(TableStatus $table): string
    {
        $parts = [];
        if ($table->schema !== null) {
            $parts[] = $this->connection->quoteIdentifier($table->schema);
        }
        $parts[] = $this->connection->quoteIdentifier($table->name);

        return implode('.', $parts);
    }

    private function isBinary(ColumnMeta $column): bool
    {
        return in_array(strtolower($column->dataType->name), ['binary', 'varbinary', 'blob', 'bytea', 'raw', 'longblob'], true);
    }
}
