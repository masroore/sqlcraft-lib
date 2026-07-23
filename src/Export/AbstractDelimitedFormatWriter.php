<?php

declare(strict_types=1);

namespace SQLCraft\Export;

use InvalidArgumentException;
use SQLCraft\Contracts\Export\FormatWriterInterface;
use SQLCraft\Contracts\Export\SinkInterface;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\TableStatus;

abstract class AbstractDelimitedFormatWriter implements FormatWriterInterface
{
    private bool $headerWritten = false;

    #[\Override]
    public function writeHeader(SinkInterface $sink, DumpOptions $options): void
    {
    }

    #[\Override]
    public function writeTableHeader(SinkInterface $sink, TableStatus $table, DumpOptions $options): void
    {
        $this->headerWritten = false;
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
        if ($rows === []) {
            return;
        }

        $separator = $options->csvSeparator ?? $this->defaultSeparator();
        if ($separator === '') {
            throw new InvalidArgumentException('Delimited format separator cannot be empty.');
        }

        if (! $this->headerWritten) {
            $sink->write($this->renderRecord(
                array_map(static fn (ColumnMeta $column): string => $column->name, $columns),
                $separator,
            ));
            $this->headerWritten = true;
        }

        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = $this->renderValue($row[$column->name] ?? null, $column, $options->nullRepresentation);
            }
            $sink->write($this->renderRecord($values, $separator));
        }
    }

    #[\Override]
    public function writeTableFooter(SinkInterface $sink, TableStatus $table): void
    {
    }

    #[\Override]
    public function writeFooter(SinkInterface $sink, DumpOptions $options): void
    {
    }

    abstract protected function defaultSeparator(): string;

    /** @param list<string> $values */
    private function renderRecord(array $values, string $separator): string
    {
        return implode($separator, array_map(
            fn (string $value): string => $this->quoteField($value, $separator),
            $values,
        )) . "\r\n";
    }

    private function quoteField(string $value, string $separator): string
    {
        if (strpbrk($value, $separator . "\"\r\n") === false) {
            return $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
    }

    private function renderValue(mixed $value, ColumnMeta $column, string $nullRepresentation): string
    {
        if ($value === null) {
            return $nullRepresentation;
        }

        if ($this->isBinary($column)) {
            if (! is_string($value)) {
                throw new InvalidArgumentException('Binary column values must be strings.');
            }

            return base64_encode($value);
        }

        return match (true) {
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => $value,
            default => throw new InvalidArgumentException('Delimited values must be scalar or null.'),
        };
    }

    private function isBinary(ColumnMeta $column): bool
    {
        return in_array(strtolower($column->dataType->name), ['binary', 'varbinary', 'blob', 'bytea', 'raw', 'longblob'], true);
    }
}
