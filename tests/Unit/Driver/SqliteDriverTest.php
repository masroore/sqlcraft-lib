<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Driver;

use PHPUnit\Framework\TestCase;
use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Driver\SqliteDriver;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\ConnectionParameters;

final class SqliteDriverTest extends TestCase
{
    public function test_it_builds_sq_lite_dsns_and_opens_connections(): void
    {
        $platform = new SqlitePlatform();
        $driver = new SqliteDriver(new PdoConnectionFactory(new PdoExceptionTranslator()), $platform);

        self::assertSame('sqlite::memory:', $driver->buildDsn(new ConnectionParameters()));
        self::assertSame('sqlite:/tmp/app.sqlite', $driver->buildDsn(new ConnectionParameters(database: '/tmp/app.sqlite')));
        self::assertSame('sqlite', $driver->getName());
        self::assertSame(['sqlite'], $driver->getPdoDriverNames());

        $connection = $driver->connect(new ConnectionParameters());
        self::assertSame($platform, $driver->getPlatform($connection));
        self::assertSame([['value' => 1]], $connection->query('SELECT 1 AS value')->fetchAll());
    }
}
