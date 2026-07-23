<?php

declare(strict_types=1);

namespace SQLCraft\Export;

use InvalidArgumentException;
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
        $sink->write($prefix . '{"table":' . json_encode($table->name, JSON_THROW_ON_ERROR) . ',"rows":[');
    }

    /** @param list<string> $ddlStatements */
    #[\Override]
    public function writeTableDdl(SinkInterface $sink, TableStatus $table, array $ddlStatements): void
    {
    }

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
            $encoded = json_encode($record, $flags | JSON_THROW_ON_ERROR);
            /** @psalm-suppress PossiblyFalseOperand */
            $sink->write($prefix . $encoded);
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
        $opts = $options->jsonOptions ?? new JsonExportOptions();
        if ($opts->pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return $flags;
    }

    private function prepareValue(mixed $value, ColumnMeta $column): bool|float|int|string|null
    {
        if ($value === null) {
            return null;
        }

        if ($this->isBinary($column)) {
            return base64_encode($this->requireString($value, 'Binary column values must be strings.'));
        }

        return match (true) {
            is_bool($value), is_int($value), is_float($value) => $value,
            is_string($value) => $value,
            default => $this->stringify($value),
        };
    }

    private function requireString(mixed $value, string $message): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? '1' : '0',
            $value instanceof \Stringable => (string) $value,
            default => throw new InvalidArgumentException('JSON export values must be scalar, Stringable, or null.'),
        };
    }

    private function isBinary(ColumnMeta $column): bool
    {
        return in_array(strtolower($column->dataType->name), ['binary', 'varbinary', 'blob', 'bytea', 'raw', 'longblob'], true);
    }
}
