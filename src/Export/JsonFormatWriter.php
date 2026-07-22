<?php

declare(strict_types=1);

namespace SQLCraft\Export;

use SQLCraft\Contracts\Export\FormatWriterInterface;
use SQLCraft\Contracts\Export\SinkInterface;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\TableStatus;

final class JsonFormatWriter implements FormatWriterInterface
{
    private bool $firstTable = true;

    private bool $firstRow = true;

    #[\Override]
    public function getFormatName(): string
    {
        return 'json';
    }

    #[\Override]
    public function writeHeader(SinkInterface $sink, DumpOptions $options): void
    {
        $sink->write('[');
    }

    #[\Override]
    public function writeTableHeader(SinkInterface $sink, TableStatus $table, DumpOptions $options): void
    {
        $prefix = $this->firstTable ? "\n  " : ",\n  ";
        $this->firstTable = false;
        $this->firstRow = true;
        $sink->write($prefix.'{"table":'.json_encode($table->name, JSON_THROW_ON_ERROR).',"rows":[');
    }

    /** @param list<string> $ddlStatements */
    #[\Override]
    public function writeTableDdl(SinkInterface $sink, TableStatus $table, array $ddlStatements): void {}

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<ColumnMeta>  $columns
     */
    #[\Override]
    public function writeRows(
        SinkInterface $sink,
        TableStatus $table,
        array $rows,
        array $columns,
        DumpOptions $options,
    ): void {
        $flags = $this->jsonFlags($options);
        foreach ($rows as $row) {
            $record = [];
            foreach ($columns as $column) {
                $record[$column->name] = $this->prepareValue($row[$column->name] ?? null, $column);
            }
            $prefix = $this->firstRow ? "\n    " : ",\n    ";
            $this->firstRow = false;
            $sink->write($prefix.json_encode($record, $flags));
        }
    }

    #[\Override]
    public function writeTableFooter(SinkInterface $sink, TableStatus $table): void
    {
        $sink->write("\n  ]}");
    }

    #[\Override]
    public function writeFooter(SinkInterface $sink, DumpOptions $options): void
    {
        $sink->write("\n]\n");
    }

    private function jsonFlags(DumpOptions $options): int
    {
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE;
        $opts = $options->jsonOptions ?? new JsonExportOptions;
        if ($opts->pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return $flags;
    }

    private function prepareValue(mixed $value, ColumnMeta $column): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($this->isBinary($column)) {
            return base64_encode((string) $value);
        }

        return match (true) {
            is_bool($value) => $value,
            is_int($value) => $value,
            is_float($value) => $value,
            default => (string) $value,
        };
    }

    private function isBinary(ColumnMeta $column): bool
    {
        return in_array(strtolower($column->dataType->name), ['binary', 'varbinary', 'blob', 'bytea', 'raw', 'longblob'], true);
    }
}
