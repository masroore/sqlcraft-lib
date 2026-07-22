<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Export;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\TableStatus;
use SQLCraft\Export\DumpOptions;
use SQLCraft\Export\DumpScope;
use SQLCraft\Export\StringBufferSink;
use SQLCraft\Export\XmlExportOptions;
use SQLCraft\Export\XmlFormatWriter;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\DefaultValue;

final class XmlFormatWriterTest extends TestCase
{
    public function testDocumentStructure(): void
    {
        $xml = $this->export(
            columns: [$this->column('id', 'INTEGER'), $this->column('name', 'TEXT')],
            rows: [['id' => 1, 'name' => 'Ada']],
        );

        self::assertSame('export', $xml->getName());
        self::assertSame('users', (string) $xml->table['name']);
        self::assertCount(1, $xml->table->row);
        self::assertSame('1', (string) $xml->table->row->id);
        self::assertSame('Ada', (string) $xml->table->row->name);
    }

    public function testCustomRootAndRowElements(): void
    {
        $xml = $this->export(
            columns: [$this->column('id', 'INTEGER')],
            rows: [['id' => 7]],
            options: new XmlExportOptions(rootElement: 'dump', rowElement: 'record'),
        );

        self::assertSame('dump', $xml->getName());
        self::assertCount(1, $xml->table->record);
        self::assertSame('7', (string) $xml->table->record->id);
    }

    public function testNullBecomesEmptyElement(): void
    {
        $output = $this->exportRaw(
            columns: [$this->column('name', 'TEXT')],
            rows: [['name' => null]],
        );

        self::assertMatchesRegularExpression('/<name\s*\/>/', $output);
    }

    public function testBinaryColumnBase64Attribute(): void
    {
        $bytes = "\x00\x01";
        $xml = $this->export(
            columns: [$this->column('payload', 'BLOB')],
            rows: [['payload' => $bytes]],
            table: 'files',
        );

        $payload = $xml->table->row->payload;
        self::assertSame('base64', (string) $payload['encoding']);
        self::assertSame(base64_encode($bytes), (string) $payload);
    }

    public function testColumnNameSanitisation(): void
    {
        $output = $this->exportRaw(
            columns: [$this->column('1bad-col', 'TEXT')],
            rows: [['1bad-col' => 'x']],
        );

        self::assertStringContainsString('<_1bad-col>', $output);
        self::assertStringNotContainsString('<1bad-col>', $output);
    }

    public function testValidXmlOutput(): void
    {
        $output = $this->exportRaw(
            columns: [$this->column('id', 'INTEGER'), $this->column('label', 'TEXT')],
            rows: [['id' => 1, 'label' => 'a & b <c>']],
        );

        $parsed = simplexml_load_string($output);
        self::assertInstanceOf(SimpleXMLElement::class, $parsed);
    }

    /**
     * @param  list<ColumnMeta>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    private function export(
        array $columns,
        array $rows,
        ?XmlExportOptions $options = null,
        string $table = 'users',
    ): SimpleXMLElement {
        $output = $this->exportRaw($columns, $rows, $options, $table);
        $xml = simplexml_load_string($output);
        self::assertInstanceOf(SimpleXMLElement::class, $xml);

        return $xml;
    }

    /**
     * @param  list<ColumnMeta>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    private function exportRaw(
        array $columns,
        array $rows,
        ?XmlExportOptions $xmlOptions = null,
        string $table = 'users',
    ): string {
        $sink = new StringBufferSink;
        $writer = new XmlFormatWriter;
        $options = new DumpOptions(
            format: 'xml',
            scope: DumpScope::table('shop', $table),
            xmlOptions: $xmlOptions,
        );
        $status = new TableStatus($table);

        $writer->writeHeader($sink, $options);
        $writer->writeTableHeader($sink, $status, $options);
        $writer->writeRows($sink, $status, $rows, $columns, $options);
        $writer->writeTableFooter($sink, $status);
        $writer->writeFooter($sink, $options);

        return $sink->contents();
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
