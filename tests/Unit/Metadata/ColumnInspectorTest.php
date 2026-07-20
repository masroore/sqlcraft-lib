<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\Exceptions\ObjectNotFoundException;
use SQLCraft\Metadata\ColumnInspector;
use SQLCraft\Metadata\SqliteMetadataFactory;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final class ColumnInspectorTest extends TestCase
{
    public function testItHydratesColumnsUsingPlatformSqlAndKeysByName(): void
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

    public function testItThrowsWhenTheRequestedColumnDoesNotExist(): void
    {
        $table = new QualifiedName(new Identifier('users'));
        [$connection] = $this->connectionWithRows('PRAGMA table_info("users")', []);

        $this->expectException(ObjectNotFoundException::class);
        (new ColumnInspector(new SqliteMetadataFactory()))->getColumn($connection, $table, new Identifier('missing'));
    }

    /**
     * @param list<array<string, bool|float|int|string|null>> $rows
     * @return array{0: ConnectionInterface, 1: ResultInterface}
     */
    private function connectionWithRows(string $sql, array $rows): array
    {
        $platform = self::createMock(PlatformInterface::class);
        $platform->expects(self::once())->method('getColumnsSql')->with(self::isInstanceOf(QualifiedName::class))->willReturn($sql);
        $result = self::createMock(ResultInterface::class);
        $result->expects(self::once())->method('fetchAll')->willReturn($rows);
        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('getPlatform')->willReturn($platform);
        $connection->expects(self::once())->method('query')->with($sql)->willReturn($result);

        return [$connection, $result];
    }
}
