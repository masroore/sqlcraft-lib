<?php

declare(strict_types=1);

namespace SQLCraft\Export;

use DateTimeInterface;
use InvalidArgumentException;
use LogicException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use SQLCraft\Contracts\Export\FormatWriterInterface;
use SQLCraft\Contracts\Export\SinkInterface;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\TableStatus;
use Stringable;

final class XlsxFormatWriter implements FormatWriterInterface
{
    private ?Writer $writer = null;

    private ?string $tmpPath = null;

    private int $sheetCount = 0;

    private bool $headerRowWritten = false;

    #[\Override]
    public function getFormatName(): string
    {
        return 'xlsx';
    }

    #[\Override]
    public function writeHeader(SinkInterface $sink, DumpOptions $options): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sqlcraft_xlsx_');
        if ($tmp === false) {
            throw new RuntimeException('Failed to create temporary file for XLSX export.');
        }

        $path = $tmp . '.xlsx';
        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Failed to prepare temporary XLSX path.');
        }

        $this->tmpPath = $path;
        $this->sheetCount = 0;
        $this->writer = new Writer(new Options);
        $this->writer->openToFile($path);
    }

    #[\Override]
    public function writeTableHeader(SinkInterface $sink, TableStatus $table, DumpOptions $options): void
    {
        $writer = $this->writer();
        if ($this->sheetCount > 0) {
            $writer->addNewSheetAndMakeItCurrent();
        }
        $this->sheetCount++;
        $this->headerRowWritten = false;

        $sheet = $writer->getCurrentSheet();
        $sheet->setName($this->sheetName($table, $options));

        $xlsxOptions = $options->xlsxOptions ?? new XlsxExportOptions;
        if ($xlsxOptions->freezeHeaderRow) {
            $sheet->setSheetView((new SheetView)->setFreezeRow(2));
        }
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
        $writer = $this->writer();

        if (! $this->headerRowWritten) {
            $writer->addRow(Row::fromValues(array_map(
                static fn (ColumnMeta $column): string => $column->name,
                $columns,
            )));
            $this->headerRowWritten = true;
        }

        foreach ($rows as $row) {
            $cells = [];
            foreach ($columns as $column) {
                $cells[] = $this->cellValue($row[$column->name] ?? null, $column);
            }
            $writer->addRow(new Row($cells));
        }
    }

    #[\Override]
    public function writeTableFooter(SinkInterface $sink, TableStatus $table): void {}

    #[\Override]
    public function writeFooter(SinkInterface $sink, DumpOptions $options): void
    {
        $path = $this->tmpPath;
        $writer = $this->writer;

        try {
            if ($writer === null || $path === null) {
                throw new LogicException('XlsxFormatWriter: writeHeader() not called.');
            }

            $writer->close();
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException('Failed to read temporary XLSX file.');
            }
            $sink->write($contents);
        } finally {
            $this->writer = null;
            $this->sheetCount = 0;
            $this->headerRowWritten = false;
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
            $this->tmpPath = null;
        }
    }

    private function writer(): Writer
    {
        return $this->writer ?? throw new LogicException('XlsxFormatWriter: writeHeader() not called.');
    }

    private function sheetName(TableStatus $table, DumpOptions $options): string
    {
        $prefix = ($options->xlsxOptions ?? new XlsxExportOptions)->sheetPrefix ?? '';
        $name = $prefix . $table->name;
        $name = preg_replace('/[\\\\\/\?\*\[\]]/', '_', $name) ?? $name;

        return mb_substr($name, 0, 31);
    }

    private function cellValue(mixed $value, ColumnMeta $column): Cell
    {
        if ($value === null) {
            return Cell::fromValue('');
        }

        if ($this->isBinary($column)) {
            if (! is_string($value)) {
                throw new InvalidArgumentException('Binary column values must be strings.');
            }

            return Cell::fromValue(base64_encode($value));
        }

        if (is_bool($value) || is_int($value) || is_float($value) || is_string($value) || $value instanceof DateTimeInterface) {
            return Cell::fromValue($value);
        }

        if ($value instanceof Stringable) {
            return Cell::fromValue((string) $value);
        }

        throw new InvalidArgumentException('XLSX export values must be scalar, DateTimeInterface, Stringable, or null.');
    }

    private function isBinary(ColumnMeta $column): bool
    {
        return in_array(strtolower($column->dataType->name), ['binary', 'varbinary', 'blob', 'bytea', 'raw', 'longblob'], true);
    }
}
