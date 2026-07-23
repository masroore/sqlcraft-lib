<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use SQLCraft\Capabilities\Capability;
use SQLCraft\Capabilities\CapabilityNotSupportedException;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\Contracts\Platform\IntrospectionDialectInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Metadata\MySQLMetadataFactory;
use SQLCraft\Metadata\ViewInspector;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final class ViewInspectorTest extends TestCase
{
    public function test_it_hydrates_views_and_reads_definitions(): void
    {
        $view = new QualifiedName(new Identifier('active_users'));
        $platform = self::createMock(PlatformInterface::class);
        $introspection = self::createMock(IntrospectionDialectInterface::class);
        $platform->method('introspection')->willReturn($introspection);
        $introspection->method('getViewsSql')->with('app')->willReturn('views');
        $introspection->method('getViewDefinitionSql')->with($view)->willReturn('definition');
        $introspection->method('getMaterializedViewsSql')->with('app')->willReturn('materialized');

        $views = self::createMock(ResultInterface::class);
        $views->method('fetchAll')->willReturn([[
            'view_name' => 'active_users',
            'table_schema' => 'app',
            'view_definition' => 'SELECT 1',
            'materialized' => false,
        ]]);
        $definition = self::createMock(ResultInterface::class);
        $definition->method('fetchAssoc')->willReturn(['definition' => 'SELECT 1']);
        $materialized = self::createMock(ResultInterface::class);
        $materialized->method('fetchAll')->willReturn([[
            'view_name' => 'daily_users',
            'table_schema' => 'app',
            'view_definition' => 'SELECT 2',
            'materialized' => true,
        ]]);

        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('getPlatform')->willReturn($platform);
        $connection->expects(self::exactly(3))->method('query')->willReturnCallback(
            static function (string $sql) use ($views, $definition, $materialized): ResultInterface {
                return match ($sql) {
                    'views' => $views,
                    'definition' => $definition,
                    'materialized' => $materialized,
                    default => throw new \LogicException('Unexpected view SQL.'),
                };
            },
        );

        $inspector = new ViewInspector(new MySQLMetadataFactory);
        $viewCollection = $inspector->getViews($connection, 'app');
        $definitionSql = $inspector->getViewDefinition($connection, $view);
        $materializedCollection = $inspector->getMaterializedViews($connection, 'app');

        self::assertSame('SELECT 1', $viewCollection->get('active_users')->definition);
        self::assertSame('SELECT 1', $definitionSql);
        self::assertTrue($materializedCollection->get('daily_users')->materialized);
    }

    public function test_materialized_view_capability_errors_are_not_hidden(): void
    {
        $platform = self::createMock(PlatformInterface::class);
        $introspection = self::createMock(IntrospectionDialectInterface::class);
        $platform->method('introspection')->willReturn($introspection);
        $introspection->method('getMaterializedViewsSql')->willThrowException(
            CapabilityNotSupportedException::for(Capability::MaterializedView, 'mysql'),
        );
        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('getPlatform')->willReturn($platform);

        $this->expectException(CapabilityNotSupportedException::class);

        (new ViewInspector(new MySQLMetadataFactory))->getMaterializedViews($connection);
    }
}
