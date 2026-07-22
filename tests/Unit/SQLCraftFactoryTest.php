<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SQLCraft\ValueObjects\ConnectionParameters;
use SQLCraft\SQLCraftFactory;

final class SQLCraftFactoryTest extends TestCase
{
    public function testSessionThrowsWhenDriverIsNull(): void
    {
        $factory = new SQLCraftFactory();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('DatabaseDriver enum case');

        $factory->session(new ConnectionParameters(database: ':memory:'));
    }
}
