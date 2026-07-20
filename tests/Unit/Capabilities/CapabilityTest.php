<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Capabilities;

use PHPUnit\Framework\TestCase;
use SQLCraft\Capabilities\Capability;
use SQLCraft\Capabilities\CapabilityNotSupportedException;
use SQLCraft\Capabilities\CapabilitySet;
use SQLCraft\Capabilities\ExtendedCapability;

final class CapabilityTest extends TestCase
{
    public function testCapabilityEnumUsesStableSerializedValues(): void
    {
        self::assertSame('table', Capability::Table->value);
        self::assertSame('check', Capability::CheckConstraints->value);
        self::assertSame('partitions', Capability::Partitions->value);
        self::assertSame(Capability::Trigger, Capability::from('trigger'));
    }

    public function testExtendedCapabilityComparesByName(): void
    {
        $parquet = new ExtendedCapability('duckdb.parquet');

        self::assertSame('duckdb.parquet', $parquet->name);
        self::assertTrue($parquet->equals(new ExtendedCapability('duckdb.parquet')));
        self::assertFalse($parquet->equals(new ExtendedCapability('duckdb.json')));
    }

    public function testCapabilitySetQueriesAndIteratesCoreAndExtendedCapabilities(): void
    {
        $parquet = new ExtendedCapability('duckdb.parquet');
        $capabilities = new CapabilitySet([
            Capability::Table,
            Capability::Trigger,
            $parquet,
        ]);

        self::assertCount(3, $capabilities);
        self::assertTrue($capabilities->has(Capability::Table));
        self::assertTrue($capabilities->has(Capability::Trigger));
        self::assertTrue($capabilities->has($parquet));
        self::assertFalse($capabilities->has(Capability::Sequence));
        self::assertSame(
            [Capability::Table, Capability::Trigger, $parquet],
            $capabilities->toArray(),
        );
        self::assertSame(
            [Capability::Table, Capability::Trigger, $parquet],
            iterator_to_array($capabilities),
        );
    }

    public function testCapabilitySetRequiresSupportedCapabilities(): void
    {
        $capabilities = new CapabilitySet([Capability::Table]);

        $capabilities->require(Capability::Table);
        self::expectException(CapabilityNotSupportedException::class);
        $capabilities->require(Capability::Sequence);
    }

    public function testCapabilitySetIntersectsWithoutMutatingEitherSet(): void
    {
        $parquet = new ExtendedCapability('duckdb.parquet');
        $capabilities = new CapabilitySet([Capability::Table, Capability::Trigger, $parquet]);
        $other = new CapabilitySet([Capability::Trigger, $parquet, Capability::Sequence]);

        $intersection = $capabilities->intersect($other);

        self::assertSame([Capability::Trigger, $parquet], $intersection->toArray());
        self::assertSame(3, $capabilities->count());
        self::assertSame(3, $other->count());
    }
}
