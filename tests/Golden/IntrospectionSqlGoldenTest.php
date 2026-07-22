<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Golden;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SQLCraft\Capabilities\CapabilityNotSupportedException;
use SQLCraft\Contracts\Platform\IntrospectionDialectInterface;
use SQLCraft\Platform\MariaDbPlatform;
use SQLCraft\Platform\MySQLPlatform;
use SQLCraft\Platform\PostgreSQLPlatform;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\Platform\SqlServerPlatform;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final class IntrospectionSqlGoldenTest extends TestCase
{
    /** @return iterable<string, array{IntrospectionDialectInterface, string, string, ?string}> */
    public static function platformProvider(): iterable
    {
        yield 'mysql' => [new MySQLPlatform, 'app', 'public', 'mysql'];
        yield 'mariadb' => [new MariaDbPlatform, 'app', 'public', 'mariadb'];
        yield 'pgsql' => [new PostgreSQLPlatform, 'app', 'public', 'pgsql'];
        yield 'sqlite' => [new SqlitePlatform, 'main', 'main', 'sqlite'];
        yield 'sqlserver' => [new SqlServerPlatform, 'app', 'public', 'sqlserver'];
    }

    #[DataProvider('platformProvider')]
    public function test_introspection_sql_matches_golden_file(
        IntrospectionDialectInterface $platform,
        string $database,
        string $schema,
        string $fixture,
    ): void {
        $table = new QualifiedName(
            object: new Identifier('users'),
            schema: new Identifier($schema),
            catalog: new Identifier($database),
        );
        $snapshot = $this->snapshot($platform, $database, $schema, $table);
        $expected = file_get_contents(__DIR__ . '/fixtures/' . $fixture . '-introspection.sql');

        self::assertIsString($expected);
        self::assertSame($expected, $snapshot);
    }

    /** @param callable(): string $operation */
    private function capture(callable $operation): string
    {
        try {
            return $operation();
        } catch (CapabilityNotSupportedException $exception) {
            return 'UNSUPPORTED: ' . $exception->getMessage();
        }
    }

    private function snapshot(IntrospectionDialectInterface $platform, string $database, string $schema, QualifiedName $table): string
    {
        $sql = [
            'getDatabasesSql' => $this->capture(fn (): string => $platform->getDatabasesSql()),
            'getSchemasSql' => $this->capture(fn (): string => $platform->getSchemasSql()),
            'getTypesSql' => $this->capture(fn (): string => $platform->getTypesSql($schema)),
            'getTablesSql' => $this->capture(fn (): string => $platform->getTablesSql($database, $schema)),
            'getColumnsSql' => $this->capture(fn (): string => $platform->getColumnsSql($table)),
            'getAllColumnsSql' => $this->capture(fn (): string => $platform->getAllColumnsSql($database, $schema)),
            'getAllIndexesSql' => $this->capture(fn (): string => $platform->getAllIndexesSql($database, $schema)),
            'getAllForeignKeysSql' => $this->capture(fn (): string => $platform->getAllForeignKeysSql($database, $schema)),
            'getTableStatusSql' => $this->capture(fn (): string => $platform->getTableStatusSql($table)),
            'getViewsSql' => $this->capture(fn (): string => $platform->getViewsSql($schema)),
            'getViewDefinitionSql' => $this->capture(fn (): string => $platform->getViewDefinitionSql($table)),
            'getIndexesSql' => $this->capture(fn (): string => $platform->getIndexesSql($table)),
            'getForeignKeysSql' => $this->capture(fn (): string => $platform->getForeignKeysSql($table)),
            'getReferencingForeignKeysSql' => $this->capture(fn (): string => $platform->getReferencingForeignKeysSql($table)),
            'getTriggersSql' => $this->capture(fn (): string => $platform->getTriggersSql($table)),
            'getRoutinesSql' => $this->capture(fn (): string => $platform->getRoutinesSql($schema)),
            'getCheckConstraintsSql' => $this->capture(fn (): string => $platform->getCheckConstraintsSql($table)),
        ];

        return implode('', array_map(
            static fn (string $method, string $query): string => $method . ': ' . $query . "\n",
            array_keys($sql),
            $sql,
        ));
    }
}
