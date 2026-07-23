<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Export;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\TableStatus;
use SQLCraft\Export\CsvFormatWriter;
use SQLCraft\Export\CsvSemicolonFormatWriter;
use SQLCraft\Export\DumpOptions;
use SQLCraft\Export\DumpScope;
use SQLCraft\Export\SqlFormatWriter;
use SQLCraft\Export\StringBufferSink;
use SQLCraft\Export\TableSectionStyle;
use SQLCraft\Export\TsvFormatWriter;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\DefaultValue;

final class FormatWriterTest extends TestCase
{
    public function test_sql_writer_renders_table_ddl_and_quoted_insert_batches(): void
    {
        $sink = new StringBufferSink();
        $table = new TableStatus('orders', schema: 'shop');
        $columns = $this->columns();
        $options = new DumpOptions('sql', DumpScope::table('shop', 'orders'), tableStyle: TableSectionStyle::DropCreate);
        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $name): string => '"' . $name . '"');
        $connection->method('quoteValue')->willReturnCallback(static fn (mixed $value): string => match (true) {
            $value === null => 'NULL',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => "'" . str_replace("'", "''", $value) . "'",
            default => throw new \InvalidArgumentException(),
        });
        $connection->method('getPlatform')->willReturn(new SqlitePlatform());
        $writer = new SqlFormatWriter($connection);

        $writer->writeHeader($sink, $options);
        $writer->writeTableHeader($sink, $table, $options);
        $writer->writeTableDdl($sink, $table, ['CREATE TABLE "orders" ("id" INTEGER)']);
        $writer->writeRows($sink, $table, [
            ['id' => 1, 'name' => "O'Reilly", 'payload' => "\x00\x01"],
            ['id' => 2, 'name' => '', 'payload' => null],
        ], $columns, $options);
        $writer->writeTableFooter($sink, $table);
        $writer->writeFooter($sink, $options);

        self::assertSame(
            "-- SQLCraft dump\n\n"
            . "-- Table: orders\n"
            . "DROP TABLE IF EXISTS \"shop\".\"orders\";\n"
            . "CREATE TABLE \"orders\" (\"id\" INTEGER);\n"
            . 'INSERT INTO "shop"."orders" ("id", "name", "payload") VALUES '
            . "(1, 'O''Reilly', X'0001'), (2, '', NULL);\n\n"
            . "-- End SQLCraft dump\n",
            $sink->contents(),
        );
    }

    public function test_csv_writer_uses_rfc4180_escaping_and_null_token(): void
    {
        $sink = new StringBufferSink();
        $table = new TableStatus('orders');
        $options = new DumpOptions('csv', DumpScope::table('shop', 'orders'));
        $writer = new CsvFormatWriter();
        $columns = $this->columns();

        $writer->writeTableHeader($sink, $table, $options);
        $writer->writeRows($sink, $table, [
            ['id' => 1, 'name' => "A, \"quoted\"\nname", 'payload' => "\x01\x02"],
        ], $columns, $options);
        $writer->writeRows($sink, $table, [
            ['id' => 2, 'name' => null, 'payload' => null],
        ], $columns, $options);

        self::assertSame(
            "id,name,payload\r\n"
            . "1,\"A, \"\"quoted\"\"\nname\",AQI=\r\n"
            . "2,\\N,\\N\r\n",
            $sink->contents(),
        );
    }

    public function test_semicolon_and_tsv_writers_expose_their_format_and_default_separators(): void
    {
        $table = new TableStatus('items');
        $columns = [$this->column('value', 'TEXT')];
        $options = new DumpOptions('csv-semicolon', DumpScope::table('shop', 'items'));

        $semicolonSink = new StringBufferSink();
        $semicolonWriter = new CsvSemicolonFormatWriter();
        $semicolonWriter->writeTableHeader($semicolonSink, $table, $options);
        $semicolonWriter->writeRows($semicolonSink, $table, [['value' => 'a;b']], $columns, $options);

        $tsvSink = new StringBufferSink();
        $tsvWriter = new TsvFormatWriter();
        $tsvWriter->writeTableHeader($tsvSink, $table, $options);
        $tsvWriter->writeRows($tsvSink, $table, [['value' => "a\tb"]], $columns, $options);

        self::assertSame('csv-semicolon', $semicolonWriter->getFormatName());
        self::assertSame("value\r\n\"a;b\"\r\n", $semicolonSink->contents());
        self::assertSame('tsv', $tsvWriter->getFormatName());
        self::assertSame("value\r\n\"a\tb\"\r\n", $tsvSink->contents());
    }

    /** @return list<ColumnMeta> */
    private function columns(): array
    {
        return [
            $this->column('id', 'INTEGER'),
            $this->column('name', 'TEXT'),
            $this->column('payload', 'BLOB'),
        ];
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
