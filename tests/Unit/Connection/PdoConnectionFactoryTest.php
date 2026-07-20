<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Connection;

use PHPUnit\Framework\TestCase;
use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Driver\DriverInterface;
use SQLCraft\ValueObjects\ConnectionParameters;

final class PdoConnectionFactoryTest extends TestCase
{
    public function testItDelegatesConnectionCreationToTheDriver(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $driver = $this->createMock(DriverInterface::class);
        $parameters = new ConnectionParameters(database: ':memory:');

        $driver
            ->expects(self::once())
            ->method('connect')
            ->with($parameters)
            ->willReturn($connection);

        self::assertSame($connection, (new PdoConnectionFactory())->connect($driver, $parameters));
    }
}
