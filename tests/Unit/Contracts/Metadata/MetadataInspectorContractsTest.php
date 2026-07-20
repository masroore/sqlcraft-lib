<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Contracts\Metadata;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Metadata\CheckConstraintInspectorInterface;
use SQLCraft\Contracts\Metadata\DatabaseInspectorInterface;
use SQLCraft\Contracts\Metadata\ColumnInspectorInterface;
use SQLCraft\Contracts\Metadata\ForeignKeyInspectorInterface;
use SQLCraft\Contracts\Metadata\IndexInspectorInterface;
use SQLCraft\Contracts\Metadata\PrivilegeInspectorInterface;
use SQLCraft\Contracts\Metadata\RoutineInspectorInterface;
use SQLCraft\Contracts\Metadata\SequenceInspectorInterface;
use SQLCraft\Contracts\Metadata\ServerInspectorInterface;
use SQLCraft\Contracts\Metadata\TableInspectorInterface;
use SQLCraft\Contracts\Metadata\TriggerInspectorInterface;
use SQLCraft\Contracts\Metadata\UserInspectorInterface;
use SQLCraft\Contracts\Metadata\ViewInspectorInterface;

final class MetadataInspectorContractsTest extends TestCase
{
    public function testInspectorPortsExposeThePlannedMethods(): void
    {
        $inspectors = [
            DatabaseInspectorInterface::class => ['getSchemas', 'getSequences', 'getTypes'],
            ServerInspectorInterface::class => [
                'getServerInfo', 'getDatabases', 'getVariables', 'getStatus',
                'getProcessList', 'getCharsets', 'getCollations',
            ],
            TableInspectorInterface::class => [
                'getTables', 'streamTables', 'getTableStatus', 'getParentTables', 'getPartitions',
            ],
            ColumnInspectorInterface::class => ['getColumns', 'getAllColumns', 'getColumn'],
            IndexInspectorInterface::class => ['getIndexes'],
            ForeignKeyInspectorInterface::class => ['getForeignKeys', 'getReferencingKeys'],
            ViewInspectorInterface::class => ['getViews', 'getViewDefinition', 'getMaterializedViews'],
            RoutineInspectorInterface::class => ['getFunctions', 'getProcedures', 'getRoutineDetail'],
            TriggerInspectorInterface::class => ['getTriggers'],
            SequenceInspectorInterface::class => ['getSequences'],
            CheckConstraintInspectorInterface::class => ['getCheckConstraints'],
            UserInspectorInterface::class => ['getUsers'],
            PrivilegeInspectorInterface::class => ['getPrivileges'],
        ];

        foreach ($inspectors as $interface => $expected) {
            $reflection = new \ReflectionClass($interface);

            self::assertTrue($reflection->isInterface());
            self::assertSame($expected, array_map(
                static fn (\ReflectionMethod $method): string => $method->getName(),
                $reflection->getMethods(),
            ));
        }
    }
}
