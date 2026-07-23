<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Connection;

use PHPUnit\Framework\TestCase;
use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Exceptions\ConnectionFailedException;
use SQLCraft\ValueObjects\ConnectionParameters;

final class PdoConnectionFactoryTest extends TestCase
{
    public function test_it_creates_a_pdo_connection_without_exposing_pdo_outside_connection(): void
    {
        $platform = $this->createMock(PlatformInterface::class);
        $platform->method('getName')->willReturn('sqlite');
        $factory = new PdoConnectionFactory(new PdoExceptionTranslator());
        $connection = $factory->connect('sqlite::memory:', new ConnectionParameters(database: 'app'), $platform, 'memory');

        self::assertSame('memory', $connection->getName());
        self::assertSame('app', $connection->getDatabaseName());
        self::assertTrue($connection->isConnected());
        self::assertSame([['value' => 1]], $connection->query('SELECT 1 AS value')->fetchAll());
    }

    public function test_it_translates_connection_failures(): void
    {
        $platform = $this->createMock(PlatformInterface::class);
        $factory = new PdoConnectionFactory(new PdoExceptionTranslator());

        $this->expectException(ConnectionFailedException::class);
        $factory->connect('sqlite:/path/that/does/not/exist/db.sqlite', new ConnectionParameters(), $platform);
    }
}
