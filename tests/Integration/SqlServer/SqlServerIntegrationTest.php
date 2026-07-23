<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Integration\SqlServer;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Driver\SqlServerDriver;
use SQLCraft\Platform\SqlServerPlatform;
use SQLCraft\ValueObjects\ConnectionParameters;

#[Group('mssql')]
final class SqlServerIntegrationTest extends TestCase
{
    private ?ConnectionInterface $connection = null;

    #[\Override]
    protected function setUp(): void
    {
        if (getenv('SQLCRAFT_RUN_ENGINE_INTEGRATION') !== '1') {
            self::markTestSkipped('Set SQLCRAFT_RUN_ENGINE_INTEGRATION=1 with the SQL Server service running.');
        }

        $factory = new PdoConnectionFactory(new PdoExceptionTranslator());
        $parameters = new ConnectionParameters(
            host: $this->environment('SQLCRAFT_MSSQL_HOST', 'mssql'),
            port: (int) $this->environment('SQLCRAFT_MSSQL_PORT', '1433'),
            database: $this->environment('SQLCRAFT_MSSQL_DB', 'sqlcraft_test'),
            username: $this->environment('SQLCRAFT_MSSQL_USER', 'sa'),
            password: $this->environment('SQLCRAFT_MSSQL_PASS', 'SQLcraft_Test1!'),
        );
        $this->connection = (new SqlServerDriver($factory, new SqlServerPlatform()))->connect($parameters);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->connection?->close();
        $this->connection = null;
    }

    public function test_connection_reports_sql_server_and_executes_queries(): void
    {
        $connection = $this->connection();

        self::assertSame('sqlserver', $connection->getPlatformName());
        self::assertGreaterThanOrEqual(11, $connection->getServerVersion()->major);
        self::assertSame([['value' => 1]], $connection->query('SELECT 1 AS value')->fetchAll());
    }

    public function test_sql_server_ddl_and_pagination_use_the_platform_dialect(): void
    {
        $connection = $this->connection();
        $quoted = '[dbo].[sqlcraft_platform_fixture]';
        $connection->execute('IF OBJECT_ID(N\'dbo.sqlcraft_platform_fixture\', N\'U\') IS NOT NULL DROP TABLE ' . $quoted);
        $connection->execute('CREATE TABLE ' . $quoted . ' ([id] INT IDENTITY(1,1) NOT NULL PRIMARY KEY, [value] NVARCHAR(100) NOT NULL)');

        try {
            for ($id = 1; $id <= 5; $id++) {
                $connection->execute('INSERT INTO ' . $quoted . ' ([value]) VALUES (?)', ['row-' . $id]);
            }

            $sql = $connection->getPlatform()->queryDialect()->applyPagination('SELECT [id], [value] FROM ' . $quoted . ' ORDER BY [id]', 2, 2);
            self::assertSame(
                [['id' => 3, 'value' => 'row-3'], ['id' => 4, 'value' => 'row-4']],
                $connection->query($sql)->fetchAll(),
            );
        } finally {
            $connection->execute('DROP TABLE ' . $quoted);
        }
    }

    private function environment(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : $value;
    }

    private function connection(): ConnectionInterface
    {
        if (! $this->connection instanceof ConnectionInterface) {
            self::fail('SQL Server connection was not initialized.');
        }

        return $this->connection;
    }
}
