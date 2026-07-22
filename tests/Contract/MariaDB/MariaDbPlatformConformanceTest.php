<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Contract\MariaDB;

use PHPUnit\Framework\Attributes\Group;
use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Driver\MySQLDriver;
use SQLCraft\Platform\MariaDbPlatform;
use SQLCraft\Tests\Contract\PlatformConformanceTestCase;
use SQLCraft\ValueObjects\ConnectionParameters;

#[Group('contract')]
final class MariaDbPlatformConformanceTest extends PlatformConformanceTestCase
{
    private ?MariaDbPlatform $mariaDbPlatform = null;

    #[\Override]
    protected function createConnection(): ConnectionInterface
    {
        $this->mariaDbPlatform = new MariaDbPlatform;
        $driver = new MySQLDriver(new PdoConnectionFactory(new PdoExceptionTranslator), $this->mariaDbPlatform);

        return $driver->connect(new ConnectionParameters(
            host: $this->environment('SQLCRAFT_MARIADB_HOST', 'mariadb'),
            port: (int) ($this->environment('SQLCRAFT_MARIADB_PORT', '3306')),
            database: $this->environment('SQLCRAFT_MARIADB_DB', 'sqlcraft_test'),
            username: $this->environment('SQLCRAFT_MARIADB_USER', 'sqlcraft'),
            password: $this->environment('SQLCRAFT_MARIADB_PASS', 'secret'),
        ));
    }

    private function environment(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    #[\Override]
    protected function platform(): PlatformInterface
    {
        if (! $this->mariaDbPlatform instanceof MariaDbPlatform) {
            throw new \LogicException('Platform is not initialized.');
        }

        return $this->mariaDbPlatform;
    }
}
