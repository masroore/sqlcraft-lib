<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Contract\SQLite;

use PHPUnit\Framework\Attributes\Group;
use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Driver\SqliteDriver;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\Tests\Contract\PlatformConformanceTestCase;
use SQLCraft\ValueObjects\ConnectionParameters;

#[Group('contract')]
final class SqlitePlatformConformanceTest extends PlatformConformanceTestCase
{
    private ?SqlitePlatform $sqlitePlatform = null;

    #[\Override]
    protected function createConnection(): ConnectionInterface
    {
        $this->sqlitePlatform = new SqlitePlatform();
        $driver = new SqliteDriver(new PdoConnectionFactory(new PdoExceptionTranslator()), $this->sqlitePlatform);

        return $driver->connect(new ConnectionParameters(database: ':memory:'));
    }

    #[\Override]
    protected function platform(): PlatformInterface
    {
        if (!$this->sqlitePlatform instanceof \SQLCraft\Platform\SqlitePlatform) {
            throw new \LogicException('Platform is not initialized.');
        }

        return $this->sqlitePlatform;
    }
}
