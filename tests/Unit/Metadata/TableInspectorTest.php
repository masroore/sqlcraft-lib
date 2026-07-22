<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Metadata;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SQLCraft\Capabilities\Capability;
use SQLCraft\Capabilities\CapabilityNotSupportedException;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Metadata\SqliteMetadataFactory;
use SQLCraft\Metadata\TableInspector;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final class TableInspectorTest extends TestCase
{
    public function test_it_hydrates_buffered_and_streaming_table_statuses(): void
    {
        $platform = self::createMock(PlatformInterface::class);
        $platform->expects(self::exactly(2))->method('getTablesSql')->with('app', 'public')->willReturn('tables');

        $buffered = self::createMock(ResultInterface::class);
        $buffered->expects(self::once())->method('fetchAll')->willReturn([
            ['table_name' => 'users', 'table_type' => 'BASE TABLE', 'table_schema' => 'public'],
            ['table_name' => 'users_view', 'table_type' => 'VIEW', 'table_schema' => 'public'],
        ]);
        $streaming = self::createMock(ResultInterface::class);
        $streaming->method('getIterator')->willReturn(new \ArrayIterator([
            ['table_name' => 'events', 'table_type' => 'BASE TABLE'],
        ]));

        $streamingResult = $streaming;
        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('getDatabaseName')->willReturn('app');
        $connection->method('getPlatform')->willReturn($platform);
        $connection->expects(self::exactly(2))->method('query')->willReturnCallback(
            static function (string $sql, array $params = [], bool $streaming = false) use ($buffered, $streamingResult): ResultInterface {
                if ($sql !== 'tables') {
                    throw new \LogicException('Unexpected SQL.');
                }

                return $streaming ? $streamingResult : $buffered;
            },
        );

        $inspector = new TableInspector(new SqliteMetadataFactory);
        $tables = $inspector->getTables($connection, 'public');
        $streamed = iterator_to_array($inspector->streamTables($connection, 'public'));

        self::assertSame(['users', 'users_view'], array_keys(iterator_to_array($tables)));
        self::assertTrue($tables->get('users_view')->isView);
        self::assertSame(['events'], array_keys($streamed));
    }

    public function test_it_performs_single_table_and_relationship_queries(): void
    {
        $table = new QualifiedName(new Identifier('child'), new Identifier('public'), new Identifier('app'));
        $platform = self::createMock(PlatformInterface::class);
        $platform->expects(self::once())->method('getTableStatusSql')->with($table)->willReturn('status');
        $platform->expects(self::once())->method('getParentTablesSql')->with($table)->willReturn('parents');
        $platform->expects(self::once())->method('getPartitionsSql')->with($table)->willReturn('partitions');

        $statusResult = self::createMock(ResultInterface::class);
        $statusResult->method('fetchAssoc')->willReturn(['table_name' => 'child', 'rows' => 4]);
        $parentResult = self::createMock(ResultInterface::class);
        $parentResult->method('fetchAll')->willReturn([['table_name' => 'parent', 'schema' => 'public', 'catalog' => 'app']]);
        $partitionResult = self::createMock(ResultInterface::class);
        $partitionResult->method('fetchAll')->willReturn([[
            'name' => 'child_2026',
            'schema' => 'public',
            'method' => 'RANGE',
            'expression' => 'created_at',
            'parent_table' => 'child',
            'bound' => 'FROM (2026) TO (2027)',
        ]]);

        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('getPlatform')->willReturn($platform);
        $connection->expects(self::exactly(3))->method('query')->willReturnCallback(
            static function (string $sql) use ($statusResult, $parentResult, $partitionResult): ResultInterface {
                return match ($sql) {
                    'status' => $statusResult,
                    'parents' => $parentResult,
                    'partitions' => $partitionResult,
                    default => throw new \LogicException('Unexpected SQL.'),
                };
            },
        );

        $inspector = new TableInspector(new SqliteMetadataFactory);
        $status = $inspector->getTableStatus($connection, $table);
        $parents = $inspector->getParentTables($connection, $table);
        $partitions = $inspector->getPartitions($connection, $table);

        self::assertSame(4, $status->rows);
        self::assertSame('parent', $parents->get('parent')->object->name);
        self::assertSame('RANGE', $partitions->get('child_2026')->method);
    }

    public function test_it_returns_empty_parent_collection_when_dialect_has_no_inheritance(): void
    {
        $platform = self::createMock(PlatformInterface::class);
        $platform->method('getParentTablesSql')->willReturn('');
        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('getPlatform')->willReturn($platform);

        $parents = (new TableInspector(new SqliteMetadataFactory))->getParentTables(
            $connection,
            new QualifiedName(new Identifier('users')),
        );

        self::assertCount(0, $parents);
    }

    public function test_it_rejects_table_listing_without_configured_database(): void
    {
        $platform = self::createMock(PlatformInterface::class);
        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('getDatabaseName')->willReturn(null);
        $connection->method('getPlatform')->willReturn($platform);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('database name is required');

        (new TableInspector(new SqliteMetadataFactory))->getTables($connection);
    }

    public function test_unsupported_partition_dialect_exception_remains_visible(): void
    {
        $platform = self::createMock(PlatformInterface::class);
        $platform->method('getPartitionsSql')->willThrowException(
            CapabilityNotSupportedException::for(Capability::Partitions, 'sqlite'),
        );
        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('getPlatform')->willReturn($platform);

        $this->expectException(CapabilityNotSupportedException::class);

        (new TableInspector(new SqliteMetadataFactory))->getPartitions(
            $connection,
            new QualifiedName(new Identifier('users')),
        );
    }
}
