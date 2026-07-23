<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\Contracts\Platform\IntrospectionDialectInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Metadata\IndexInspector;
use SQLCraft\Metadata\SqliteMetadataFactory;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\IndexType;
use SQLCraft\ValueObjects\QualifiedName;

final class IndexInspectorTest extends TestCase
{
    public function test_it_hydrates_indexes_using_the_platform_dialect(): void
    {
        $table = new QualifiedName(new Identifier('users'));
        $sql = 'PRAGMA index_list("users")';
        $platform = self::createMock(PlatformInterface::class);
        $introspection = self::createMock(IntrospectionDialectInterface::class);
        $platform->method('introspection')->willReturn($introspection);
        $introspection->expects(self::once())->method('getIndexesSql')->with($table)->willReturn($sql);
        $result = self::createMock(ResultInterface::class);
        $result->expects(self::once())->method('fetchAll')->willReturn([[
            'name' => 'users_email',
            'column_name' => 'email',
            'unique' => 1,
            'type' => 'UNIQUE',
        ]]);
        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('getPlatform')->willReturn($platform);
        $connection->expects(self::once())->method('query')->with($sql)->willReturn($result);

        $indexes = (new IndexInspector(new SqliteMetadataFactory))->getIndexes($connection, $table);

        self::assertSame(IndexType::UNIQUE, $indexes->get('users_email')->type);
        self::assertSame('email', $indexes->get('users_email')->columns[0]->columnName);
    }
}
