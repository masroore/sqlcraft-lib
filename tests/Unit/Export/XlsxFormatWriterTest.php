<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Export;

use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\TestCase;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\TableStatus;
use SQLCraft\Export\DumpOptions;
use SQLCraft\Export\DumpScope;
use SQLCraft\Export\StringBufferSink;
use SQLCraft\Export\XlsxExportOptions;
use SQLCraft\Export\XlsxFormatWriter;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\DefaultValue;

final class XlsxFormatWriterTest extends TestCase
{
    public function test_single_table_one_sheet(): void
    {
        $sheets = $this->readSheets($this->export([
            'users' => [
                'columns' => [$this->column('id', 'INTEGER'), $this->column('name', 'TEXT')],
                'rows' => [['id' => 1, 'name' => 'Ada']],
            ],
        ]));

        self::assertSame(['users'], array_keys($sheets));
        self::assertSame(['id', 'name'], $sheets['users'][0]);
        self::assertSame([1, 'Ada'], $sheets['users'][1]);
    }

    public function test_multiple_tables_multiple_sheets(): void
    {
        $sheets = $this->readSheets($this->export([
            'users' => [
                'columns' => [$this->column('id', 'INTEGER')],
                'rows' => [['id' => 1]],
            ],
            'orders' => [
                'columns' => [$this->column('id', 'INTEGER')],
                'rows' => [['id' => 2]],
            ],
        ]));

        self::assertSame(['users', 'orders'], array_keys($sheets));
    }

    public function test_sheet_prefix(): void
    {
        $sheets = $this->readSheets($this->export(
            [
                'users' => [
                    'columns' => [$this->column('id', 'INTEGER')],
                    'rows' => [['id' => 1]],
                ],
            ],
            new XlsxExportOptions(sheetPrefix: 'db_'),
        ));

        self::assertSame(['db_users'], array_keys($sheets));
    }

    public function test_header_row_present(): void
    {
        $sheets = $this->readSheets($this->export([
            'users' => [
                'columns' => [$this->column('email', 'TEXT'), $this->column('active', 'INTEGER')],
                'rows' => [['email' => 'a@b.c', 'active' => 1]],
            ],
        ]));

        self::assertSame(['email', 'active'], $sheets['users'][0]);
    }

    public function test_null_becomes_empty_cell(): void
    {
        $sheets = $this->readSheets($this->export([
            'users' => [
                'columns' => [$this->column('id', 'INTEGER'), $this->column('name', 'TEXT')],
                'rows' => [['id' => 1, 'name' => null]],
            ],
        ]));

        self::assertSame(['id', 'name'], $sheets['users'][0]);
        self::assertCount(2, $sheets['users'][1]);
        self::assertSame(1, $sheets['users'][1][0]);
        // OpenSpout reads empty cells as null (EmptyCell).
        self::assertTrue($sheets['users'][1][1] === null || $sheets['users'][1][1] === '');
    }

    public function test_binary_base64(): void
    {
        $bytes = "\x01\x02";
        $sheets = $this->readSheets($this->export([
            'files' => [
                'columns' => [$this->column('payload', 'BLOB')],
                'rows' => [['payload' => $bytes]],
            ],
        ]));

        self::assertSame(base64_encode($bytes), $sheets['files'][1][0]);
    }

    public function test_temp_file_cleaned_up(): void
    {
        $before = $this->tempXlsxFiles();
        $this->export([
            'users' => [
                'columns' => [$this->column('id', 'INTEGER')],
                'rows' => [['id' => 1]],
            ],
        ]);
        $after = $this->tempXlsxFiles();

        self::assertSame($before, $after);
    }

    /**
     * @param  array<string, array{columns: list<ColumnMeta>, rows: list<array<string, mixed>>}>  $tables
     */
    private function export(array $tables, ?XlsxExportOptions $xlsxOptions = null): string
    {
        $sink = new StringBufferSink();
        $writer = new XlsxFormatWriter();
        $options = new DumpOptions(
            format: 'xlsx',
            scope: DumpScope::database('shop'),
            xlsxOptions: $xlsxOptions,
        );

        $writer->writeHeader($sink, $options);
        foreach ($tables as $name => $spec) {
            $table = new TableStatus($name);
            $writer->writeTableHeader($sink, $table, $options);
            $writer->writeRows($sink, $table, $spec['rows'], $spec['columns'], $options);
            $writer->writeTableFooter($sink, $table);
        }
        $writer->writeFooter($sink, $options);

        return $sink->contents();
    }

    /**
     * @return array<string, list<list<mixed>>>
     */
    private function readSheets(string $binary): array
    {
        $path = tempnam(sys_get_temp_dir(), 'sqlcraft_xlsx_test_');
        self::assertNotFalse($path);
        $xlsx = $path . '.xlsx';
        rename($path, $xlsx);
        file_put_contents($xlsx, $binary);

        try {
            $reader = new Reader();
            $reader->open($xlsx);
            $sheets = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                $rows = [];
                foreach ($sheet->getRowIterator() as $row) {
                    $values = [];
                    foreach ($row->getCells() as $cell) {
                        $values[] = $cell->getValue();
                    }
                    $rows[] = $values;
                }
                $sheets[$sheet->getName()] = $rows;
            }
            $reader->close();

            return $sheets;
        } finally {
            @unlink($xlsx);
        }
    }

    /** @return list<string> */
    private function tempXlsxFiles(): array
    {
        $matches = glob(sys_get_temp_dir() . '/sqlcraft_xlsx_*');
        $matches = $matches === false ? [] : $matches;
        sort($matches);

        return $matches;
    }

    private function column(string $name, string $type): ColumnMeta
    {
        return new ColumnMeta(
            name: $name,
            dataType: new DataType($type),
            nullable: true,
            autoIncrement: false,
            primary: false,
            generated: false,
            default: DefaultValue::nullValue(),
            collation: null,
            comment: null,
            onUpdate: null,
            privileges: [],
            origName: null,
            defaultConstraintName: null,
        );
    }
}
