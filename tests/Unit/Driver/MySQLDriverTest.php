<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Driver;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\PdoConnectionFactoryInterface;
use SQLCraft\Driver\MySQLDriver;
use SQLCraft\Platform\MySQLPlatform;
use SQLCraft\ValueObjects\ConnectionParameters;

final class MySQLDriverTest extends TestCase
{
    public function testItBuildsHostAndSocketDsns(): void
    {
        $factory = self::createMock(PdoConnectionFactoryInterface::class);
        $driver = new MySQLDriver($factory, new MySQLPlatform());

        self::assertSame(
            'mysql:host=db.example;port=3307;dbname=shop;charset=utf8mb4',
            $driver->buildDsn(new ConnectionParameters(host: 'db.example', port: 3307, database: 'shop', charset: 'utf8mb4')),
        );
        self::assertSame(
            'mysql:unix_socket=/var/run/mysql.sock;dbname=shop',
            $driver->buildDsn(new ConnectionParameters(socket: '/var/run/mysql.sock', database: 'shop')),
        );
        self::assertSame('mysql', $driver->getName());
        self::assertSame(['mysql'], $driver->getPdoDriverNames());
    }

    public function testItUsesThePdoFactorySeam(): void
    {
        $factory = self::createMock(PdoConnectionFactoryInterface::class);
        $platform = new MySQLPlatform();
        $connection = self::createMock(ConnectionInterface::class);
        $parameters = new ConnectionParameters(database: 'shop');
        $factory->expects(self::once())->method('connect')->with('mysql:host=127.0.0.1;port=3306;dbname=shop', $parameters, $platform)->willReturn($connection);
        $driver = new MySQLDriver($factory, $platform);

        self::assertSame($connection, $driver->connect($parameters));
        self::assertSame($platform, $driver->getPlatform($connection));
    }
}
