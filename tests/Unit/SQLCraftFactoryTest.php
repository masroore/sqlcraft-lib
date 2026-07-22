<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SQLCraft\SQLCraftFactory;
use SQLCraft\ValueObjects\ConnectionParameters;

final class SQLCraftFactoryTest extends TestCase
{
    public function test_session_throws_when_driver_is_null(): void
    {
        $factory = new SQLCraftFactory;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('DatabaseDriver enum case');

        $factory->session(new ConnectionParameters(database: ':memory:'));
    }
}
