<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use SQLCraft\Collections\TableCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Metadata\CheckConstraintInspectorInterface;
use SQLCraft\Contracts\Metadata\ColumnInspectorInterface;
use SQLCraft\Contracts\Metadata\DatabaseInspectorInterface;
use SQLCraft\Contracts\Metadata\ForeignKeyInspectorInterface;
use SQLCraft\Contracts\Metadata\IndexInspectorInterface;
use SQLCraft\Contracts\Metadata\MetadataCacheInterface;
use SQLCraft\Contracts\Metadata\RoutineInspectorInterface;
use SQLCraft\Contracts\Metadata\SequenceInspectorInterface;
use SQLCraft\Contracts\Metadata\ServerInspectorInterface;
use SQLCraft\Contracts\Metadata\TableInspectorInterface;
use SQLCraft\Contracts\Metadata\TriggerInspectorInterface;
use SQLCraft\Contracts\Metadata\UserInspectorInterface;
use SQLCraft\Contracts\Metadata\ViewInspectorInterface;
use SQLCraft\DTO\TableStatus;
use SQLCraft\Schema\NullMetadataCache;
use SQLCraft\Schema\SchemaManager;

final class SchemaManagerTest extends TestCase
{
    public function testFacadeDelegatesAndCachesTableListing(): void
    {
        $status = new TableStatus('users');
        $tableCollection = new TableCollection(['users' => $status]);
        $tableInspector = self::createMock(TableInspectorInterface::class);
        $tableInspector->expects(self::once())->method('getTables')->with(self::isInstanceOf(ConnectionInterface::class), null)->willReturn($tableCollection);

        $cache = self::createMock(MetadataCacheInterface::class);
        $cache->expects(self::once())->method('remember')->with('sqlite/app/tables:')->willReturnCallback(
            static function (string $key, callable $loader, int $ttl = 0): TableCollection {
                /** @var TableCollection $result */
                $result = $loader();

                return $result;
            },
        );
        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('getPlatformName')->willReturn('sqlite');
        $connection->method('getDatabaseName')->willReturn('app');

        $manager = new SchemaManager(
            serverInspector: self::createStub(ServerInspectorInterface::class),
            databaseInspector: self::createStub(DatabaseInspectorInterface::class),
            tableInspector: $tableInspector,
            columnInspector: self::createStub(ColumnInspectorInterface::class),
            indexInspector: self::createStub(IndexInspectorInterface::class),
            foreignKeyInspector: self::createStub(ForeignKeyInspectorInterface::class),
            viewInspector: self::createStub(ViewInspectorInterface::class),
            routineInspector: self::createStub(RoutineInspectorInterface::class),
            triggerInspector: self::createStub(TriggerInspectorInterface::class),
            sequenceInspector: self::createStub(SequenceInspectorInterface::class),
            checkConstraintInspector: self::createStub(CheckConstraintInspectorInterface::class),
            userInspector: self::createStub(UserInspectorInterface::class),
            cache: $cache,
        );

        self::assertSame($tableCollection, $manager->getTables($connection));
    }
}
