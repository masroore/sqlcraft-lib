<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Contract\PostgreSQL;

use PHPUnit\Framework\Attributes\Group;
use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Driver\PostgreSQLDriver;
use SQLCraft\Platform\PostgreSQLPlatform;
use SQLCraft\Tests\Contract\PlatformConformanceTestCase;
use SQLCraft\ValueObjects\ConnectionParameters;

#[Group('contract')]
final class PostgreSQLPlatformConformanceTest extends PlatformConformanceTestCase
{
    private ?PostgreSQLPlatform $postgresqlPlatform = null;

    #[\Override]
    protected function createConnection(): ConnectionInterface
    {
        $this->postgresqlPlatform = new PostgreSQLPlatform();
        $driver = new PostgreSQLDriver(new PdoConnectionFactory(new PdoExceptionTranslator()), $this->postgresqlPlatform);

        return $driver->connect(new ConnectionParameters(
            host: $this->environment('SQLCRAFT_PGSQL_HOST', 'postgres'),
            port: (int) ($this->environment('SQLCRAFT_PGSQL_PORT', '5432')),
            database: $this->environment('SQLCRAFT_PGSQL_DB', 'sqlcraft_test'),
            username: $this->environment('SQLCRAFT_PGSQL_USER', 'sqlcraft'),
            password: $this->environment('SQLCRAFT_PGSQL_PASS', 'secret'),
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
        if (! $this->postgresqlPlatform instanceof PostgreSQLPlatform) {
            throw new \LogicException('Platform is not initialized.');
        }

        return $this->postgresqlPlatform;
    }
}
