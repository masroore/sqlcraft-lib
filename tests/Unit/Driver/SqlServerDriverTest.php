<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Driver;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\PdoConnectionFactoryInterface;
use SQLCraft\Driver\SqlServerDriver;
use SQLCraft\Platform\SqlServerPlatform;
use SQLCraft\ValueObjects\ConnectionParameters;

final class SqlServerDriverTest extends TestCase
{
    public function testItBuildsHostAndSocketDsns(): void
    {
        $driver = new SqlServerDriver(self::createMock(PdoConnectionFactoryInterface::class), new SqlServerPlatform());

        self::assertSame(
            'sqlsrv:Server=db.example,11433;Database=shop',
            $driver->buildDsn(new ConnectionParameters(host: 'db.example', port: 11433, database: 'shop')),
        );
        self::assertSame(
            'sqlsrv:Server=/var/run/sqlserver;Database=shop',
            $driver->buildDsn(new ConnectionParameters(socket: '/var/run/sqlserver', database: 'shop')),
        );
        self::assertSame('sqlserver', $driver->getName());
        self::assertSame(['sqlsrv'], $driver->getPdoDriverNames());
    }

    public function testItUsesThePdoFactorySeam(): void
    {
        $factory = self::createMock(PdoConnectionFactoryInterface::class);
        $platform = new SqlServerPlatform();
        $connection = self::createMock(ConnectionInterface::class);
        $parameters = new ConnectionParameters(database: 'shop');
        $factory->expects(self::once())
            ->method('connect')
            ->with('sqlsrv:Server=127.0.0.1;Database=shop', $parameters, $platform)
            ->willReturn($connection);
        $driver = new SqlServerDriver($factory, $platform);

        self::assertSame($connection, $driver->connect($parameters));
        self::assertSame($platform, $driver->getPlatform($connection));
    }
}
