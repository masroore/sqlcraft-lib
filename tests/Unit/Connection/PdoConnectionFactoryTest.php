<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Connection;

use PDO;
use PHPUnit\Framework\TestCase;
use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\ValueObjects\ConnectionParameters;

final class PdoConnectionFactoryTest extends TestCase
{
    public function testItCreatesAPdoConnectionWithoutExposingPdoOutsideConnection(): void
    {
        $platform = $this->createMock(PlatformInterface::class);
        $platform->method('getName')->willReturn('sqlite');
        $factory = new PdoConnectionFactory(new PdoExceptionTranslator());
        $connection = $factory->connect('sqlite::memory:', new ConnectionParameters(), $platform, 'memory');

        self::assertSame('memory', $connection->getName());
        self::assertTrue($connection->isConnected());
        self::assertSame([['value' => 1]], $connection->query('SELECT 1 AS value')->fetchAll());
    }

    public function testItTranslatesConnectionFailures(): void
    {
        $platform = $this->createMock(PlatformInterface::class);
        $factory = new PdoConnectionFactory(new PdoExceptionTranslator());

        $this->expectException(\SQLCraft\Exceptions\ConnectionFailedException::class);
        $factory->connect('sqlite:/path/that/does/not/exist/db.sqlite', new ConnectionParameters(), $platform);
    }
}
