<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Export;

use PHPUnit\Framework\TestCase;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\TableStatus;
use SQLCraft\Export\DumpOptions;
use SQLCraft\Export\DumpScope;
use SQLCraft\Export\JsonExportOptions;
use SQLCraft\Export\JsonFormatWriter;
use SQLCraft\Export\StringBufferSink;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\DefaultValue;

final class JsonFormatWriterTest extends TestCase
{
    public function testEmptyExport(): void
    {
        $sink = new StringBufferSink;
        $writer = new JsonFormatWriter;
        $options = $this->options();

        $writer->writeHeader($sink, $options);
        $writer->writeFooter($sink, $options);

        self::assertSame("[\n]\n", $sink->contents());
    }

    public function testSingleTableNoRows(): void
    {
        $sink = new StringBufferSink;
        $writer = new JsonFormatWriter;
        $options = $this->options();
        $table = new TableStatus('users');

        $writer->writeHeader($sink, $options);
        $writer->writeTableHeader($sink, $table, $options);
        $writer->writeTableFooter($sink, $table);
        $writer->writeFooter($sink, $options);

        $decoded = json_decode($sink->contents(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([['table' => 'users', 'rows' => []]], $decoded);
    }

    public function testSingleTableWithRows(): void
    {
        $sink = new StringBufferSink;
        $writer = new JsonFormatWriter;
        $options = $this->options();
        $table = new TableStatus('users');
        $columns = [$this->column('id', 'INTEGER'), $this->column('name', 'TEXT')];

        $writer->writeHeader($sink, $options);
        $writer->writeTableHeader($sink, $table, $options);
        $writer->writeRows($sink, $table, [
            ['id' => 1, 'name' => 'Ada'],
            ['id' => 2, 'name' => 'Grace'],
        ], $columns, $options);
        $writer->writeTableFooter($sink, $table);
        $writer->writeFooter($sink, $options);

        $decoded = json_decode($sink->contents(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([
            [
                'table' => 'users',
                'rows' => [
                    ['id' => 1, 'name' => 'Ada'],
                    ['id' => 2, 'name' => 'Grace'],
                ],
            ],
        ], $decoded);
    }

    public function testMultipleTablesOrderPreserved(): void
    {
        $sink = new StringBufferSink;
        $writer = new JsonFormatWriter;
        $options = $this->options();
        $columns = [$this->column('id', 'INTEGER')];

        $writer->writeHeader($sink, $options);
        foreach (['alpha', 'beta'] as $name) {
            $table = new TableStatus($name);
            $writer->writeTableHeader($sink, $table, $options);
            $writer->writeRows($sink, $table, [['id' => 1]], $columns, $options);
            $writer->writeTableFooter($sink, $table);
        }
        $writer->writeFooter($sink, $options);

        $decoded = json_decode($sink->contents(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['alpha', 'beta'], array_column($decoded, 'table'));
    }

    public function testNullBecomesJsonNull(): void
    {
        $sink = new StringBufferSink;
        $writer = new JsonFormatWriter;
        $options = $this->options();
        $table = new TableStatus('users');
        $columns = [$this->column('name', 'TEXT')];

        $writer->writeHeader($sink, $options);
        $writer->writeTableHeader($sink, $table, $options);
        $writer->writeRows($sink, $table, [['name' => null]], $columns, $options);
        $writer->writeTableFooter($sink, $table);
        $writer->writeFooter($sink, $options);

        self::assertStringContainsString('"name":null', str_replace([' ', "\n"], '', $sink->contents()));
        $decoded = json_decode($sink->contents(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNull($decoded[0]['rows'][0]['name']);
    }

    public function testBinaryColumnIsBase64Encoded(): void
    {
        $sink = new StringBufferSink;
        $writer = new JsonFormatWriter;
        $options = $this->options();
        $table = new TableStatus('files');
        $columns = [$this->column('payload', 'BLOB')];
        $bytes = "\x00\x01\x02";

        $writer->writeHeader($sink, $options);
        $writer->writeTableHeader($sink, $table, $options);
        $writer->writeRows($sink, $table, [['payload' => $bytes]], $columns, $options);
        $writer->writeTableFooter($sink, $table);
        $writer->writeFooter($sink, $options);

        $decoded = json_decode($sink->contents(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(base64_encode($bytes), $decoded[0]['rows'][0]['payload']);
    }

    public function testCompactJson(): void
    {
        $sink = new StringBufferSink;
        $writer = new JsonFormatWriter;
        $options = $this->options(new JsonExportOptions(pretty: false));
        $table = new TableStatus('users');
        $columns = [$this->column('id', 'INTEGER')];

        $writer->writeHeader($sink, $options);
        $writer->writeTableHeader($sink, $table, $options);
        $writer->writeRows($sink, $table, [['id' => 1]], $columns, $options);
        $writer->writeTableFooter($sink, $table);
        $writer->writeFooter($sink, $options);

        $rowJson = trim(explode("\n", $sink->contents())[1] ?? '');
        // With pretty:false, json_encode of the row has no whitespace; document still uses structural newlines.
        self::assertStringNotContainsString("\n  ", json_encode(['id' => 1], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('{"id":1}', $sink->contents());
        self::assertStringNotContainsString("{\n", preg_replace('/^\s*\[|\s*\]\s*$/s', '', $sink->contents()) ?? '');
    }

    public function testPrettyJsonDefault(): void
    {
        $sink = new StringBufferSink;
        $writer = new JsonFormatWriter;
        $options = $this->options();
        $table = new TableStatus('users');
        $columns = [$this->column('id', 'INTEGER'), $this->column('name', 'TEXT')];

        $writer->writeHeader($sink, $options);
        $writer->writeTableHeader($sink, $table, $options);
        $writer->writeRows($sink, $table, [['id' => 1, 'name' => 'Ada']], $columns, $options);
        $writer->writeTableFooter($sink, $table);
        $writer->writeFooter($sink, $options);

        self::assertStringContainsString("\n", $sink->contents());
        self::assertStringContainsString('    {', $sink->contents());
    }

    private function options(?JsonExportOptions $json = null): DumpOptions
    {
        return new DumpOptions(
            format: 'json',
            scope: DumpScope::table('shop', 'users'),
            jsonOptions: $json,
        );
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
