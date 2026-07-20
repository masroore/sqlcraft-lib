<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Contracts\Connection;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Connection\PdoConnectionFactoryInterface;

final class PdoConnectionFactoryContractTest extends TestCase
{
    public function testFactoryPortExposesOnlyConnectionCreation(): void
    {
        $methods = (new \ReflectionClass(PdoConnectionFactoryInterface::class))->getMethods();

        self::assertCount(1, $methods);
        self::assertSame('connect', $methods[0]->getName());
        self::assertTrue($methods[0]->hasReturnType());
    }
}
