<?php

declare(strict_types=1);

namespace SQLCraft\Export;

use InvalidArgumentException;
use LogicException;
use SQLCraft\Contracts\Export\FormatWriterInterface;
use SQLCraft\Contracts\Export\SinkInterface;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\TableStatus;
use XMLWriter;

final class XmlFormatWriter implements FormatWriterInterface
{
    private ?XMLWriter $writer = null;

    #[\Override]
    public function getFormatName(): string
    {
        return 'xml';
    }

    #[\Override]
    public function writeHeader(SinkInterface $sink, DumpOptions $options): void
    {
        $opts = $options->xmlOptions ?? new XmlExportOptions();
        $this->writer = new XMLWriter();
        $this->writer->openMemory();
        $this->writer->setIndent(true);
        $this->writer->setIndentString('  ');
        $this->writer->startDocument('1.0', 'UTF-8');
        $this->writer->startElement($opts->rootElement);
    }

    #[\Override]
    public function writeTableHeader(SinkInterface $sink, TableStatus $table, DumpOptions $options): void
    {
        $this->writer()->startElement('table');
        $this->writer()->writeAttribute('name', $table->name);
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
        $opts = $options->xmlOptions ?? new XmlExportOptions();
        $w = $this->writer();
        foreach ($rows as $row) {
            $w->startElement($opts->rowElement);
            foreach ($columns as $column) {
                /** @psalm-suppress MixedAssignment */
                $value = $row[$column->name] ?? null;
                $elem = $this->sanitiseElementName($column->name);
                if ($value === null) {
                    $w->writeElement($elem);
                } elseif ($this->isBinary($column)) {
                    $w->startElement($elem);
                    $w->writeAttribute('encoding', 'base64');
                    $w->text(base64_encode($this->requireString($value, 'Binary column values must be strings.')));
                    $w->endElement();
                } else {
                    $w->writeElement($elem, $this->stringify($value));
                }
            }
            $w->endElement();
            $this->flushTo($sink);
        }
    }

    #[\Override]
    public function writeTableFooter(SinkInterface $sink, TableStatus $table): void
    {
        $this->writer()->endElement();
        $this->flushTo($sink);
    }

    #[\Override]
    public function writeFooter(SinkInterface $sink, DumpOptions $options): void
    {
        $w = $this->writer();
        $w->endElement();
        $w->endDocument();
        $this->flushTo($sink);
        $this->writer = null;
    }

    private function writer(): XMLWriter
    {
        return $this->writer ?? throw new LogicException('XmlFormatWriter: writeHeader() not called.');
    }

    private function flushTo(SinkInterface $sink): void
    {
        $chunk = $this->writer()->flush(true);
        $sink->write(is_string($chunk) ? $chunk : (string) $chunk);
    }

    private function sanitiseElementName(string $name): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_.\-]/', '_', $name) ?? $name;
        if (preg_match('/^[0-9\-.]/', $name) === 1) {
            $name = '_' . $name;
        }

        return $name !== '' ? $name : '_col';
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
            default => throw new InvalidArgumentException('XML export values must be scalar, Stringable, or null.'),
        };
    }

    private function isBinary(ColumnMeta $column): bool
    {
        return in_array(strtolower($column->dataType->name), ['binary', 'varbinary', 'blob', 'bytea', 'raw', 'longblob'], true);
    }
}
