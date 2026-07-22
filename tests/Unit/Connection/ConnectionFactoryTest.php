<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Connection;

use PHPUnit\Framework\TestCase;
use SQLCraft\Connection\ConnectionFactory;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Driver\DriverInterface;
use SQLCraft\ValueObjects\ConnectionParameters;

final class ConnectionFactoryTest extends TestCase
{
    public function test_it_creates_a_connection_through_the_injected_driver(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $driver = $this->createMock(DriverInterface::class);
        $parameters = new ConnectionParameters(database: ':memory:');

        $driver
            ->expects(self::once())
            ->method('connect')
            ->with($parameters)
            ->willReturn($connection);

        self::assertSame($connection, (new ConnectionFactory($driver))->connect($parameters));
    }
}
