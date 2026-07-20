<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Capabilities;

use PHPUnit\Framework\TestCase;
use SQLCraft\Capabilities\Capability;
use SQLCraft\Capabilities\PlatformCapabilityResolver;
use SQLCraft\ValueObjects\ServerVersion;

final class PlatformCapabilityResolverTest extends TestCase
{
    public function testItResolvesAlwaysOnAndVersionedCapabilities(): void
    {
        $resolver = new PlatformCapabilityResolver([
            'always' => [Capability::Table],
            'versioned' => [[Capability::DropColumn, [3, 35, 0]]],
        ]);

        self::assertTrue($resolver->resolve('sqlite', new ServerVersion('3.35.0'))->has(Capability::Table));
        self::assertTrue($resolver->resolve('sqlite', new ServerVersion('3.35.0'))->has(Capability::DropColumn));
        self::assertFalse($resolver->resolve('sqlite', new ServerVersion('3.34.0'))->has(Capability::DropColumn));
    }

    public function testItDeduplicatesCapabilities(): void
    {
        $set = (new PlatformCapabilityResolver([
            'always' => [Capability::Table, Capability::Table],
            'versioned' => [[Capability::Table, [1, 0, 0]]],
        ]))->resolve('sqlite', new ServerVersion('3.0.0'));

        self::assertSame(1, $set->count());
    }
}
