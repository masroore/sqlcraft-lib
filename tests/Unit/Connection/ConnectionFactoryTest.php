<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Connection;

use PHPUnit\Framework\TestCase;
use SQLCraft\Connection\ConnectionFactory;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\PdoConnectionFactoryInterface;
use SQLCraft\Contracts\Driver\DriverInterface;
use SQLCraft\ValueObjects\ConnectionParameters;

final class ConnectionFactoryTest extends TestCase
{
    public function testItCreatesAConnectionThroughTheInjectedPDOFactory(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $pdoFactory = $this->createMock(PdoConnectionFactoryInterface::class);
        $driver = $this->createMock(DriverInterface::class);
        $parameters = new ConnectionParameters(database: ':memory:');

        $pdoFactory
            ->expects(self::once())
            ->method('connect')
            ->with($driver, $parameters)
            ->willReturn($connection);

        self::assertSame($connection, (new ConnectionFactory($pdoFactory, $driver))->connect($parameters));
    }
}
