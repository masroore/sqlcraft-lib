<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use SQLCraft\Collections\ColumnCollection;
use SQLCraft\Collections\TableCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Metadata\ColumnInspectorInterface;
use SQLCraft\Contracts\Metadata\TableInspectorInterface;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\TableStatus;
use SQLCraft\Metadata\ExportSource;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\DefaultValue;
use SQLCraft\ValueObjects\QualifiedName;

final class ExportSourceTest extends TestCase
{
    public function test_adapts_string_table_names_to_metadata_qualified_names(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $table = new TableStatus('orders', schema: 'shop');
        $tables = self::createMock(TableInspectorInterface::class);
        $tables->expects(self::once())->method('getTableStatus')->with(
            $connection,
            self::callback(static fn (QualifiedName $name): bool => $name->object->name === 'orders' && $name->schema?->name === 'shop'),
        )->willReturn($table);
        $columns = self::createMock(ColumnInspectorInterface::class);
        $columns->expects(self::once())->method('getColumns')->with(
            $connection,
            self::callback(static fn (QualifiedName $name): bool => $name->object->name === 'orders' && $name->schema?->name === 'shop'),
        )->willReturn(new ColumnCollection([]));

        $source = new ExportSource($tables, $columns);

        self::assertSame($table, $source->getTableStatus($connection, 'orders', 'shop'));
        self::assertCount(0, $source->getColumns($connection, 'orders', 'shop'));
    }

    public function test_builds_portable_basic_create_table_ddl_from_column_metadata(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(
            static fn (string $name): string => '"' . $name . '"',
        );
        $tables = self::createMock(TableInspectorInterface::class);
        $columns = self::createMock(ColumnInspectorInterface::class);
        $columns->method('getColumns')->willReturn(new ColumnCollection([
            new ColumnMeta(
                name: 'id',
                dataType: new DataType('INTEGER'),
                nullable: false,
                autoIncrement: false,
                primary: true,
                generated: false,
                default: DefaultValue::nullValue(),
                collation: null,
                comment: null,
                onUpdate: null,
                privileges: [],
                origName: null,
                defaultConstraintName: null,
            ),
        ]));

        self::assertSame(
            ['CREATE TABLE "shop"."orders" ("id" INTEGER NOT NULL PRIMARY KEY)'],
            (new ExportSource($tables, $columns))->getTableDdl($connection, 'orders', 'shop'),
        );
    }

    public function test_delegates_table_listing_without_changing_schema(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $expected = new TableCollection([]);
        $tables = self::createMock(TableInspectorInterface::class);
        $tables->expects(self::once())->method('getTables')->with($connection, 'public')->willReturn($expected);
        $columns = self::createMock(ColumnInspectorInterface::class);

        self::assertSame($expected, (new ExportSource($tables, $columns))->getTables($connection, 'public'));
    }
}
