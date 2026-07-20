<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Contracts\Driver;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Driver\DriverInterface;

final class DriverInterfaceTest extends TestCase
{
    public function testDriverPortExposesConnectionFactoryResponsibilities(): void
    {
        self::assertSame(
            ['buildDsn', 'connect', 'getPlatform', 'getName', 'getPdoDriverNames'],
            array_map(
                static fn (\ReflectionMethod $method): string => $method->getName(),
                (new \ReflectionClass(DriverInterface::class))->getMethods(),
            ),
        );
    }
}
