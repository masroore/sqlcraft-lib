<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use SQLCraft\Collections\ColumnCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\PreparedStatementInterface;
use SQLCraft\Contracts\Import\ImportSourceInterface;
use SQLCraft\Contracts\Metadata\ColumnInspectorInterface;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\ExecutionResult;
use SQLCraft\Import\CsvImporter;
use SQLCraft\Import\CsvImportOptions;
use SQLCraft\Import\UpsertMode;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\DefaultValue;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final class CsvImporterTest extends TestCase
{
    public function test_maps_known_columns_nulls_and_binary_values_into_one_prepared_batch(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $name): string => '"' . $name . '"');
        $connection->method('getPlatformName')->willReturn('sqlite');
        $statement = self::createMock(PreparedStatementInterface::class);
        $statement->expects(self::once())->method('execute')->with([
            '1',
            'hello,world',
            "\x01\x02",
            '2',
            null,
            null,
        ])->willReturn(new ExecutionResult(2, '', 0.0, 'INSERT'));
        $connection->expects(self::once())->method('prepare')->with(
            'INSERT INTO "shop"."orders" ("id", "name", "payload") VALUES (?, ?, ?), (?, ?, ?)',
        )->willReturn($statement);
        $columns = self::createMock(ColumnInspectorInterface::class);
        $columns->expects(self::once())->method('getColumns')->with(
            $connection,
            self::callback(static fn (QualifiedName $name): bool => $name->object->name === 'orders' && $name->schema?->name === 'shop'),
        )->willReturn(new ColumnCollection([
            $this->column('id', 'INTEGER'),
            $this->column('name', 'TEXT'),
            $this->column('payload', 'BLOB'),
        ]));
        $source = self::createMock(ImportSourceInterface::class);
        $source->method('openStream')->willReturn($this->stream("id,name,payload,unknown\n1,\"hello,world\",AQI=,ignored\n2,\\N,\\N,ignored\n"));

        $result = (new CsvImporter($columns))->importCsv(
            $connection,
            new QualifiedName(new Identifier('orders'), new Identifier('shop')),
            $source,
            new CsvImportOptions(wrapInTransaction: false, batchSize: 2),
        );

        self::assertSame(1, $result->statementsExecuted);
        self::assertSame([], $result->errors);
    }

    public function test_maps_replace_mode_for_sqlite_and_validates_options(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $name): string => '"' . $name . '"');
        $connection->method('getPlatformName')->willReturn('sqlite');
        $statement = self::createMock(PreparedStatementInterface::class);
        $statement->method('execute')->willReturn(new ExecutionResult(1, '', 0.0, 'INSERT'));
        $connection->expects(self::once())->method('prepare')->with('INSERT OR REPLACE INTO "orders" ("id") VALUES (?)')->willReturn($statement);
        $columns = self::createMock(ColumnInspectorInterface::class);
        $columns->method('getColumns')->willReturn(new ColumnCollection([$this->column('id', 'INTEGER')]));
        $source = self::createMock(ImportSourceInterface::class);
        $source->method('openStream')->willReturn($this->stream("id\n1\n"));

        (new CsvImporter($columns))->importCsv(
            $connection,
            new QualifiedName(new Identifier('orders')),
            $source,
            new CsvImportOptions(upsertMode: UpsertMode::InsertOrReplace, wrapInTransaction: false),
        );

        $this->expectException(\InvalidArgumentException::class);
        new CsvImportOptions(batchSize: 0);
    }

    private function column(string $name, string $type): ColumnMeta
    {
        return new ColumnMeta($name, new DataType($type), true, false, false, false, DefaultValue::nullValue(), null, null, null, [], null, null);
    }

    /** @return resource */
    private function stream(string $contents)
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }
}
