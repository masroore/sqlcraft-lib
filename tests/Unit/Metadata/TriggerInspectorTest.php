<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\Contracts\Platform\IntrospectionDialectInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Metadata\SqliteMetadataFactory;
use SQLCraft\Metadata\TriggerInspector;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;
use SQLCraft\ValueObjects\TriggerEvent;

final class TriggerInspectorTest extends TestCase
{
    public function test_it_hydrates_triggers_using_the_platform_dialect(): void
    {
        $table = new QualifiedName(new Identifier('users'));
        $sql = "SELECT name, sql FROM sqlite_master WHERE type = 'trigger' ORDER BY name";
        $platform = self::createMock(PlatformInterface::class);
        $introspection = self::createMock(IntrospectionDialectInterface::class);
        $platform->method('introspection')->willReturn($introspection);
        $introspection->expects(self::once())->method('getTriggersSql')->with($table)->willReturn($sql);
        $result = self::createMock(ResultInterface::class);
        $result->expects(self::once())->method('fetchAll')->willReturn([[
            'name' => 'users_inserted',
            'timing' => 'BEFORE',
            'event' => 'INSERT',
            'sql' => 'BEGIN SELECT 1; END',
            'table_name' => 'users',
        ]]);
        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('getPlatform')->willReturn($platform);
        $connection->expects(self::once())->method('query')->with($sql)->willReturn($result);

        $triggers = (new TriggerInspector(new SqliteMetadataFactory))->getTriggers($connection, $table);

        self::assertSame(TriggerEvent::INSERT, $triggers->get('users_inserted')->event);
        self::assertSame('users', $triggers->get('users_inserted')->table);
    }
}
