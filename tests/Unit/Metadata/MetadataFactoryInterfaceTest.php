<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\ForeignKeyMeta;
use SQLCraft\DTO\IndexMeta;
use SQLCraft\DTO\PartitionInfo;
use SQLCraft\DTO\RoutineMeta;
use SQLCraft\DTO\TableStatus;
use SQLCraft\DTO\TriggerMeta;
use SQLCraft\Metadata\MetadataFactoryInterface;

final class MetadataFactoryInterfaceTest extends TestCase
{
    public function testFactoryExposesThePlannedTypedHydrationMethods(): void
    {
        $reflection = new \ReflectionClass(MetadataFactoryInterface::class);

        self::assertTrue($reflection->isInterface());
        self::assertSame([
            'createColumnMeta',
            'createTableStatus',
            'createPartitionInfo',
            'createIndexMeta',
            'createForeignKeyMeta',
            'createTriggerMeta',
            'createRoutineMeta',
        ], array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(),
        ));

        $expectedReturnTypes = [
            'createColumnMeta' => ColumnMeta::class,
            'createTableStatus' => TableStatus::class,
            'createPartitionInfo' => PartitionInfo::class,
            'createIndexMeta' => IndexMeta::class,
            'createForeignKeyMeta' => ForeignKeyMeta::class,
            'createTriggerMeta' => TriggerMeta::class,
            'createRoutineMeta' => RoutineMeta::class,
        ];

        foreach ($expectedReturnTypes as $methodName => $returnType) {
            $reflectionType = $reflection->getMethod($methodName)->getReturnType();

            self::assertInstanceOf(\ReflectionNamedType::class, $reflectionType);
            self::assertSame($returnType, $reflectionType->getName());
        }
    }
}
