<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Contract\MySQL;

use PHPUnit\Framework\Attributes\Group;
use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Driver\MySQLDriver;
use SQLCraft\Platform\MySQLPlatform;
use SQLCraft\Tests\Contract\PlatformConformanceTestCase;
use SQLCraft\ValueObjects\ConnectionParameters;

#[Group('contract')]
final class MySQLPlatformConformanceTest extends PlatformConformanceTestCase
{
    private ?MySQLPlatform $mysqlPlatform = null;

    #[\Override]
    protected function createConnection(): ConnectionInterface
    {
        $this->mysqlPlatform = new MySQLPlatform();
        $driver = new MySQLDriver(new PdoConnectionFactory(new PdoExceptionTranslator()), $this->mysqlPlatform);

        return $driver->connect(new ConnectionParameters(
            host: $this->environment('SQLCRAFT_MYSQL_HOST', 'mysql'),
            port: (int) ($this->environment('SQLCRAFT_MYSQL_PORT', '3306')),
            database: $this->environment('SQLCRAFT_MYSQL_DB', 'sqlcraft_test'),
            username: $this->environment('SQLCRAFT_MYSQL_USER', 'sqlcraft'),
            password: $this->environment('SQLCRAFT_MYSQL_PASS', 'secret'),
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
        if (! $this->mysqlPlatform instanceof MySQLPlatform) {
            throw new \LogicException('Platform is not initialized.');
        }

        return $this->mysqlPlatform;
    }
}
