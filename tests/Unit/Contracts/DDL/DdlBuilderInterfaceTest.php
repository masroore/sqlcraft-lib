<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Contracts\DDL;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\DDL\DdlBuilderInterface;

final class DdlBuilderInterfaceTest extends TestCase
{
    public function test_ddl_builder_port_exposes_rendering_and_execution(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(DdlBuilderInterface::class))->getMethods(),
        );

        self::assertSame(['toSql', 'execute'], $methods);
    }
}
