<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Contract\SqlServer;

use PHPUnit\Framework\Attributes\Group;
use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Driver\SqlServerDriver;
use SQLCraft\Platform\SqlServerPlatform;
use SQLCraft\Tests\Contract\PlatformConformanceTestCase;
use SQLCraft\ValueObjects\ConnectionParameters;

#[Group('contract')]
#[Group('mssql')]
final class SqlServerPlatformConformanceTest extends PlatformConformanceTestCase
{
    private ?SqlServerPlatform $sqlServerPlatform = null;

    #[\Override]
    protected function createConnection(): ConnectionInterface
    {
        $this->sqlServerPlatform = new SqlServerPlatform();
        $driver = new SqlServerDriver(new PdoConnectionFactory(new PdoExceptionTranslator()), $this->sqlServerPlatform);

        return $driver->connect(new ConnectionParameters(
            host: $this->environment('SQLCRAFT_MSSQL_HOST', 'mssql'),
            port: (int) $this->environment('SQLCRAFT_MSSQL_PORT', '1433'),
            database: $this->environment('SQLCRAFT_MSSQL_DB', 'sqlcraft_test'),
            username: $this->environment('SQLCRAFT_MSSQL_USER', 'sa'),
            password: $this->environment('SQLCRAFT_MSSQL_PASS', 'SQLcraft_Test1!'),
        ));
    }

    #[\Override]
    protected function platform(): PlatformInterface
    {
        if (!$this->sqlServerPlatform instanceof SqlServerPlatform) {
            throw new \LogicException('Platform is not initialized.');
        }

        return $this->sqlServerPlatform;
    }

    private function environment(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
