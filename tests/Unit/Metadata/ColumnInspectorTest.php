<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use SQLCraft\Collections\ColumnCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\Contracts\Platform\IntrospectionDialectInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\Exceptions\ObjectNotFoundException;
use SQLCraft\Metadata\ColumnInspector;
use SQLCraft\Metadata\SqliteMetadataFactory;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final class ColumnInspectorTest extends TestCase
{
    public function test_it_hydrates_columns_using_platform_sql_and_keys_by_name(): void
    {
        $table = new QualifiedName(new Identifier('users'));
        [$connection] = $this->connectionWithRows('PRAGMA table_info("users")', [[
            'name' => 'id',
            'type' => 'INTEGER',
            'notnull' => 1,
            'pk' => 1,
            'dflt_value' => null,
        ]]);

        $columns = (new ColumnInspector(new SqliteMetadataFactory()))->getColumns($connection, $table);

        self::assertCount(1, $columns);
        self::assertInstanceOf(ColumnMeta::class, $columns->get('id'));
        self::assertSame('id', $columns->get('id')->name);
    }

    public function test_it_hydrates_all_columns_with_one_batch_query_grouped_by_table(): void
    {
        $platform = self::createMock(PlatformInterface::class);
        $introspection = self::createMock(IntrospectionDialectInterface::class);
        $platform->method('introspection')->willReturn($introspection);
        $introspection->expects(self::once())->method('getAllColumnsSql')->with('app', 'public')->willReturn('all-columns');
        $result = self::createMock(ResultInterface::class);
        $result->expects(self::once())->method('fetchAll')->willReturn([
            ['table_name' => 'users', 'name' => 'id', 'type' => 'INTEGER', 'notnull' => 1, 'pk' => 1],
            ['table_name' => 'users', 'name' => 'email', 'type' => 'TEXT', 'notnull' => 0, 'pk' => 0],
            ['table_name' => 'orders', 'name' => 'id', 'type' => 'INTEGER', 'notnull' => 1, 'pk' => 1],
        ]);
        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('getPlatform')->willReturn($platform);
        $connection->expects(self::once())->method('query')->with('all-columns')->willReturn($result);

        $columns = (new ColumnInspector(new SqliteMetadataFactory()))->getAllColumns($connection, 'app', 'public');

        self::assertArrayHasKey('users', $columns);
        self::assertArrayHasKey('orders', $columns);
        self::assertInstanceOf(ColumnCollection::class, $columns['users']);
        self::assertCount(2, $columns['users']);
        self::assertSame('email', $columns['users']->get('email')->name);
    }

    public function test_it_throws_when_the_requested_column_does_not_exist(): void
    {
        $table = new QualifiedName(new Identifier('users'));
        [$connection] = $this->connectionWithRows('PRAGMA table_info("users")', []);

        $this->expectException(ObjectNotFoundException::class);
        (new ColumnInspector(new SqliteMetadataFactory()))->getColumn($connection, $table, new Identifier('missing'));
    }

    /**
     * @param  list<array<string, bool|float|int|string|null>>  $rows
     * @return array{0: ConnectionInterface, 1: ResultInterface}
     */
    private function connectionWithRows(string $sql, array $rows): array
    {
        $platform = self::createMock(PlatformInterface::class);
        $introspection = self::createMock(IntrospectionDialectInterface::class);
        $platform->method('introspection')->willReturn($introspection);
        $introspection->expects(self::once())->method('getColumnsSql')->with(self::isInstanceOf(QualifiedName::class))->willReturn($sql);
        $result = self::createMock(ResultInterface::class);
        $result->expects(self::once())->method('fetchAll')->willReturn($rows);
        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('getPlatform')->willReturn($platform);
        $connection->expects(self::once())->method('query')->with($sql)->willReturn($result);

        return [$connection, $result];
    }
}
