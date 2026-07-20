<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Driver;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\PdoConnectionFactoryInterface;
use SQLCraft\Driver\PostgreSQLDriver;
use SQLCraft\Platform\PostgreSQLPlatform;
use SQLCraft\ValueObjects\ConnectionParameters;

final class PostgreSQLDriverTest extends TestCase
{
    public function testItBuildsHostAndSocketDsns(): void
    {
        $driver = new PostgreSQLDriver(self::createMock(PdoConnectionFactoryInterface::class), new PostgreSQLPlatform());

        self::assertSame(
            'pgsql:host=db.example;port=5433;dbname=shop',
            $driver->buildDsn(new ConnectionParameters(host: 'db.example', port: 5433, database: 'shop')),
        );
        self::assertSame(
            'pgsql:host=/var/run/postgresql;port=5432;dbname=shop',
            $driver->buildDsn(new ConnectionParameters(socket: '/var/run/postgresql', database: 'shop')),
        );
        self::assertSame('pgsql', $driver->getName());
        self::assertSame(['pgsql'], $driver->getPdoDriverNames());
    }

    public function testItUsesThePdoFactorySeam(): void
    {
        $factory = self::createMock(PdoConnectionFactoryInterface::class);
        $platform = new PostgreSQLPlatform();
        $connection = self::createMock(ConnectionInterface::class);
        $parameters = new ConnectionParameters(database: 'shop');
        $factory->expects(self::once())->method('connect')->with('pgsql:host=127.0.0.1;port=5432;dbname=shop', $parameters, $platform)->willReturn($connection);
        $driver = new PostgreSQLDriver($factory, $platform);

        self::assertSame($connection, $driver->connect($parameters));
        self::assertSame($platform, $driver->getPlatform($connection));
    }
}
