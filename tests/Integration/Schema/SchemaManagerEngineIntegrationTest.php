<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Integration\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Driver\MySQLDriver;
use SQLCraft\Driver\PostgreSQLDriver;
use SQLCraft\Platform\MariaDbPlatform;
use SQLCraft\Platform\MySQLPlatform;
use SQLCraft\Platform\PostgreSQLPlatform;
use SQLCraft\Schema\SchemaManagerFactory;
use SQLCraft\ValueObjects\ConnectionParameters;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final class SchemaManagerEngineIntegrationTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, string, string}> */
    public static function engineProvider(): iterable
    {
        yield 'mysql' => ['mysql', 'mysql', 'sqlcraft_test', 'sqlcraft', 'secret'];
        yield 'mariadb' => ['mariadb', 'mariadb', 'sqlcraft_test', 'sqlcraft', 'secret'];
        yield 'pgsql' => ['pgsql', 'postgres', 'sqlcraft_test', 'sqlcraft', 'secret'];
    }

    #[DataProvider('engineProvider')]
    public function test_describe_table_returns_typed_structure_for_engine(
        string $platformName,
        string $host,
        string $database,
        string $username,
        string $password,
    ): void {
        if (getenv('SQLCRAFT_RUN_ENGINE_INTEGRATION') !== '1') {
            self::markTestSkipped('Set SQLCRAFT_RUN_ENGINE_INTEGRATION=1 with engine services running.');
        }

        $connection = $this->connect($platformName, $host, $database, $username, $password);
        $users = $platformName === 'pgsql' ? '"public"."users"' : ($platformName === 'mysql' || $platformName === 'mariadb' ? '`sqlcraft_test`.`users`' : '"users"');
        $orders = $platformName === 'pgsql' ? '"public"."orders"' : ($platformName === 'mysql' || $platformName === 'mariadb' ? '`sqlcraft_test`.`orders`' : '"orders"');
        $connection->execute('DROP TABLE IF EXISTS ' . $orders);
        $connection->execute('DROP TABLE IF EXISTS ' . $users);
        $connection->execute('CREATE TABLE ' . $users . ' (id INTEGER PRIMARY KEY, email VARCHAR(255) NOT NULL UNIQUE)');
        $connection->execute('CREATE TABLE ' . $orders . ' (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, CONSTRAINT orders_user_fk FOREIGN KEY (user_id) REFERENCES users(id))');

        try {
            $manager = SchemaManagerFactory::forConnection($connection);
            $table = $platformName === 'pgsql'
                ? new QualifiedName(new Identifier('orders'), new Identifier('public'))
                : new QualifiedName(new Identifier('orders'), catalog: new Identifier('sqlcraft_test'));
            $structure = $manager->describeTable($connection, $table);

            self::assertSame('orders', $structure->status->name);
            self::assertSame('user_id', $structure->columns->get('user_id')->name);
            self::assertNotEmpty($structure->foreignKeys);
        } finally {
            $connection->execute('DROP TABLE ' . $orders);
            $connection->execute('DROP TABLE ' . $users);
        }
    }

    private function connect(string $platformName, string $host, string $database, string $username, string $password): ConnectionInterface
    {
        $factory = new PdoConnectionFactory(new PdoExceptionTranslator);
        $parameters = new ConnectionParameters(host: $host, port: $platformName === 'pgsql' ? 5432 : 3306, database: $database, username: $username, password: $password);

        return match ($platformName) {
            'mysql' => (new MySQLDriver($factory, new MySQLPlatform))->connect($parameters),
            'mariadb' => (new MySQLDriver($factory, new MariaDbPlatform))->connect($parameters),
            'pgsql' => (new PostgreSQLDriver($factory, new PostgreSQLPlatform))->connect($parameters),
            default => throw new \InvalidArgumentException('Unknown engine: ' . $platformName),
        };
    }
}
