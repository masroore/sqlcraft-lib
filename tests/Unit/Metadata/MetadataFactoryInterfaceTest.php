<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\DatabaseMeta;
use SQLCraft\DTO\ForeignKeyMeta;
use SQLCraft\DTO\IndexMeta;
use SQLCraft\DTO\PartitionInfo;
use SQLCraft\DTO\ProcessMeta;
use SQLCraft\DTO\SchemaMeta;
use SQLCraft\DTO\SequenceMeta;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\DTO\RoutineMeta;
use SQLCraft\DTO\TableStatus;
use SQLCraft\DTO\TriggerMeta;
use SQLCraft\DTO\ViewMeta;
use SQLCraft\Metadata\MetadataFactoryInterface;

final class MetadataFactoryInterfaceTest extends TestCase
{
    public function testFactoryExposesThePlannedTypedHydrationMethods(): void
    {
        $reflection = new \ReflectionClass(MetadataFactoryInterface::class);

        self::assertTrue($reflection->isInterface());
        self::assertSame([
            'createColumnMeta',
            'createDatabaseMeta',
            'createProcessMeta',
            'createTableStatus',
            'createPartitionInfo',
            'createSchemaMeta',
            'createSequenceMeta',
            'createDataType',
            'createIndexMeta',
            'createForeignKeyMeta',
            'createTriggerMeta',
            'createRoutineMeta',
            'createViewMeta',
        ], array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(),
        ));

        $expectedReturnTypes = [
            'createColumnMeta' => ColumnMeta::class,
            'createDatabaseMeta' => DatabaseMeta::class,
            'createProcessMeta' => ProcessMeta::class,
            'createTableStatus' => TableStatus::class,
            'createPartitionInfo' => PartitionInfo::class,
            'createSchemaMeta' => SchemaMeta::class,
            'createSequenceMeta' => SequenceMeta::class,
            'createDataType' => DataType::class,
            'createIndexMeta' => IndexMeta::class,
            'createForeignKeyMeta' => ForeignKeyMeta::class,
            'createTriggerMeta' => TriggerMeta::class,
            'createRoutineMeta' => RoutineMeta::class,
            'createViewMeta' => ViewMeta::class,
        ];

        foreach ($expectedReturnTypes as $methodName => $returnType) {
            $reflectionType = $reflection->getMethod($methodName)->getReturnType();

            self::assertInstanceOf(\ReflectionNamedType::class, $reflectionType);
            self::assertSame($returnType, $reflectionType->getName());
        }
    }
}
