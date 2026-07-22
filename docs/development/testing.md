# Testing Strategy

SQLCraft has four test suites with distinct purposes, plus static analysis and mutation
testing as quality gates. This document explains how each suite works, how to run it,
and how to write new tests.

## Test Suites

| Suite | Directory | Purpose |
|---|---|---|
| Unit | `tests/Unit/` | Pure logic, no I/O, fast |
| Integration | `tests/Integration/` | Real SQLite or containerised databases |
| Contract | `tests/Contract/` | Platform conformance against live engines |
| Golden | `tests/Golden/` | Snapshot tests for generated SQL |

## PHPUnit Configuration

The configuration lives in `phpunit.xml.dist` at the project root.

```xml
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/11.5/phpunit.xsd"
    bootstrap="tests/bootstrap.php"
    cacheDirectory=".phpunit.cache"
    colors="true"
    failOnRisky="true"
    failOnWarning="true"
    beStrictAboutTestsThatDoNotTestAnything="true"
>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory>tests/Integration</directory>
        </testsuite>
        <testsuite name="contract">
            <directory>tests/Contract</directory>
        </testsuite>
        <testsuite name="golden">
            <directory>tests/Golden</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

`failOnRisky` and `failOnWarning` are set to `true` so that tests which make no
assertions or produce deprecation warnings fail the build immediately.

## Running Tests

All test commands are defined as Composer scripts:

```bash
# Unit tests only (fast, no Docker required)
composer test

# Integration tests (SQLite runs locally; containers needed for other engines)
composer test:integration

# Contract/conformance tests (all live engines via Testcontainers)
composer test:contract

# Golden-file snapshot tests
composer test:golden

# Everything
composer test:all

# Static analysis
composer stan
composer psalm

# Mutation testing
composer infection
```

Or use the underlying PHPUnit command directly:

```bash
vendor/bin/phpunit --testsuite=unit
vendor/bin/phpunit --testsuite=unit --filter=CapabilityTest
vendor/bin/phpunit --coverage-html coverage/
```

## Unit Test Suite

Unit tests live in `tests/Unit/` and mirror the `src/` namespace structure.
They have no I/O and run in milliseconds. PHPUnit mocks are used for all
collaborators.

### Running

```bash
composer test
# or
vendor/bin/phpunit --testsuite=unit
```

### Writing Unit Tests for Custom DDL Builders

Mock `DdlDialectInterface` and verify that the rendered SQL matches your expectation:

```php
use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\DDL\CreateTableBuilder;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

class CreateTableBuilderTest extends TestCase
{
    public function testRendersCreateTableWithPrimaryKey(): void
    {
        $dialect = $this->createMock(DdlDialectInterface::class);
        $dialect->method('renderCreateTableStatement')
            ->willReturnCallback(fn ($table, $cols, $constraints, $opts)
                => 'CREATE TABLE t (' . implode(', ', $cols) . ')');
        $dialect->method('renderColumnDefinition')
            ->willReturn('id BIGINT NOT NULL');
        $dialect->method('renderPrimaryKeyClause')
            ->willReturn('PRIMARY KEY (id)');

        $builder = new CreateTableBuilder($dialect, new QualifiedName(new Identifier('t')));
        $sql = $builder->column('id', 'BIGINT')->primaryKey()->getSql();

        self::assertStringContainsString('CREATE TABLE', $sql);
        self::assertStringContainsString('PRIMARY KEY', $sql);
    }
}
```

### Writing Unit Tests for Value Object Invariants

Value objects enforce their own constraints. Test the invariants directly:

```php
use PHPUnit\Framework\TestCase;
use SQLCraft\ValueObjects\ServerVersion;

class ServerVersionTest extends TestCase
{
    public function testIsAtLeastReturnsTrueForEqualVersion(): void
    {
        $v = new ServerVersion('8.0.32');
        self::assertTrue($v->isAtLeast(8, 0, 32));
    }

    public function testIsAtLeastReturnsFalseForNewerRequired(): void
    {
        $v = new ServerVersion('8.0.15');
        self::assertFalse($v->isAtLeast(8, 0, 16));
    }

    public function testParsesMajorMinorOnly(): void
    {
        $v = new ServerVersion('10.3');
        self::assertTrue($v->isAtLeast(10, 3, 0));
    }
}
```

### Capability Unit Tests

```php
use SQLCraft\Capabilities\Capability;
use SQLCraft\Capabilities\CapabilityNotSupportedException;
use SQLCraft\Capabilities\CapabilitySet;

class CapabilityTest extends TestCase
{
    public function testRequireThrowsWhenMissing(): void
    {
        $caps = new CapabilitySet([Capability::Table]);
        $this->expectException(CapabilityNotSupportedException::class);
        $caps->require(Capability::Sequence);
    }

    public function testHasReturnsFalseForAbsentCapability(): void
    {
        $caps = new CapabilitySet([]);
        self::assertFalse($caps->has(Capability::View));
    }
}
```

## Integration Test Suite

Integration tests spin up real databases and execute full round-trips. SQLite tests
run without Docker. MySQL, MariaDB, PostgreSQL, and SQL Server tests use
Testcontainers via the `testcontainers/testcontainers` package.

### Running

```bash
# SQLite only (no Docker)
vendor/bin/phpunit --testsuite=integration --filter=Sqlite

# All integration tests (Docker required)
composer test:integration
```

### Testcontainers Setup

Container lifecycle is managed per test class. Extend a base class that starts and
stops the container:

```php
// tests/Integration/PostgreSQL/SchemaManagerPostgresIntegrationTest.php
use PHPUnit\Framework\TestCase;
use Testcontainers\Container\PostgreSQLContainer;
use SQLCraft\SQLCraftFactory;
use SQLCraft\ValueObjects\ConnectionParameters;

class SchemaManagerPostgresIntegrationTest extends TestCase
{
    private static PostgreSQLContainer $container;
    private DatabaseSession $session;

    public static function setUpBeforeClass(): void
    {
        self::$container = PostgreSQLContainer::make('16-alpine')
            ->withDatabase('testdb')
            ->withUsername('test')
            ->withPassword('test')
            ->run();
    }

    public static function tearDownAfterClass(): void
    {
        self::$container->stop();
    }

    protected function setUp(): void
    {
        $factory = new SQLCraftFactory();
        $this->session = $factory->session(new ConnectionParameters(
            host:     self::$container->getHost(),
            port:     self::$container->getPort(),
            database: 'testdb',
            username: 'test',
            password: 'test',
            extras:   ['driver' => 'pgsql'],
        ));
    }
}
```

### Writing Integration Tests Against SQLite

SQLite requires no container, so integration tests against SQLite run in CI without
Docker:

```php
use PHPUnit\Framework\TestCase;
use SQLCraft\SQLCraftFactory;
use SQLCraft\ValueObjects\ConnectionParameters;

class SchemaManagerSqliteIntegrationTest extends TestCase
{
    private DatabaseSession $session;

    protected function setUp(): void
    {
        $factory = new SQLCraftFactory();
        $this->session = $factory->session(new ConnectionParameters(
            database: ':memory:',
            extras:   ['driver' => 'sqlite'],
        ));

        $this->session->connection()->execute(
            'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)'
        );
    }

    public function testListTablesReturnsCreatedTable(): void
    {
        $tables = $this->session->schema()->listTables('main');
        self::assertCount(1, $tables);
        self::assertSame('users', $tables->first()->name);
    }

    public function testDescribeTableReturnsColumns(): void
    {
        $structure = $this->session->schema()->describeTable(
            \SQLCraft\ValueObjects\QualifiedName::simple('users')
        );

        self::assertCount(2, $structure->columns);
    }
}
```

## Contract / Conformance Tests

Contract tests assert that every platform implementation honours the same observable
contract regardless of engine. They run against live databases (via Testcontainers for
CI, or local servers for development).

### PlatformConformanceTestCase

All platform conformance tests extend `SQLCraft\Tests\Contract\PlatformConformanceTestCase`.
This base class:

1. Creates a fixture table `contract_fixture_rows` with 10 rows.
2. Runs shared assertions against any live engine.
3. Tears down the connection after each test.

Shared assertions include:

- `testQuotedIdentifierIsAcceptedByTheLiveEngine` — platform quoting round-trips through
  the live engine without SQL syntax errors.
- `testPaginationNeverExceedsTheRequestedLimit` — `applyPagination(sql, limit: 5, offset: 0)`
  returns exactly 5 rows.
- `testOffsetPaginationStartsAtTheRequestedRow` — `offset: 4` starts at row 5.
- `testQuotedStringIsAcceptedAsAValue` — `quoteValue("O'Reilly")` is safe to embed in SQL.
- `testLiveServerExposesTheDeclaredPlatformCapabilities` — `getCapabilitySet()` includes
  at least `Table`, `Columns`, and `Sql`.

### Writing a Platform Conformance Test

Create one class per platform, implement `createConnection()` and `platform()`:

```php
// tests/Contract/PostgreSQL/PostgreSQLPlatformConformanceTest.php
namespace SQLCraft\Tests\Contract\PostgreSQL;

use SQLCraft\Tests\Contract\PlatformConformanceTestCase;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Platform\PostgreSQLPlatform;
// ... driver bootstrap ...

class PostgreSQLPlatformConformanceTest extends PlatformConformanceTestCase
{
    protected function createConnection(): ConnectionInterface
    {
        // connect to Testcontainer or local server
    }

    protected function platform(): PlatformInterface
    {
        return new PostgreSQLPlatform();
    }
}
```

## Golden-File Tests

Golden tests capture the exact SQL strings that the introspection dialect methods produce
for each platform. They catch regressions in SQL generation without requiring a live database.

Golden files are stored as `.sql` snapshots alongside the test. When a platform's SQL
changes intentionally, update the golden file:

```bash
vendor/bin/phpunit --testsuite=golden --update-snapshots
```

The test class `tests/Golden/IntrospectionSqlGoldenTest.php` iterates over each
platform and each introspection method (`getTablesSql`, `getColumnsSql`, `getIndexesSql`,
etc.) and compares the output to the stored snapshot.

Example golden file:

```
-- golden/mysql/getColumnsSql.sql
SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'mydb'
AND TABLE_NAME = 'users' ORDER BY ORDINAL_POSITION
```

## Static Analysis

### PHPStan (level max)

```bash
composer stan
# vendor/bin/phpstan analyse --memory-limit=1G
```

Configuration: `phpstan.neon.dist`. Runs at the maximum strictness level with
`phpstan-strict-rules`. All return types, parameter types, and generics must be
fully specified.

### Psalm

```bash
composer psalm
# vendor/bin/psalm --show-info=false
```

Configuration: `psalm.xml`. Psalm runs at `errorLevel="1"` and catches issues that
PHPStan misses, particularly around template types and conditional return types.

Both tools run in CI on every push. A PR cannot merge with any errors from either tool.

## Mutation Testing with Infection

Mutation testing verifies that your tests actually detect code changes.
Infection introduces small mutations (flipping `>` to `>=`, removing `!`, etc.) and
checks whether the test suite catches them.

### Running

```bash
composer infection
# vendor/bin/infection --min-msi=80 --min-covered-msi=90
```

### Quality Gates

| Metric | Threshold |
|---|---|
| Mutation Score Indicator (MSI) | >= 80% |
| Covered Code MSI | >= 90% |

MSI measures what fraction of all mutants are killed. Covered MSI measures the fraction
of mutants in covered code that are killed. The distinction matters: uncovered code that
happens to be untested does not pollute the covered score.

Configuration: `infection.json.dist`.

Infection results are written to `infection.log` (excluded from git).

## Architecture Enforcement with Deptrac

Deptrac enforces the layered architecture so that inner layers never depend on outer ones.

```bash
composer deptrac
# vendor/bin/deptrac analyse
```

Configuration: `deptrac.yaml`. The defined layers follow the dependency rule:

```
Contracts -> (nothing internal)
ValueObjects, DTO, Collections -> Contracts
Capabilities -> Contracts, ValueObjects
Platform -> Contracts, ValueObjects, DTO, Capabilities
Connection -> Contracts, ValueObjects, Exceptions
Metadata -> Contracts, DTO, Collections, Platform
Schema -> Contracts, Metadata, Collections
DDL -> Contracts, Platform, DTO, Collections
Query -> Contracts, Platform, DTO, Collections
Execution -> Contracts, Connection, Query
Export -> Contracts, Execution, Metadata, DTO
Import -> Contracts, Execution
Security -> Contracts, Execution, Metadata
Events -> Contracts
Driver -> Contracts, Platform, Connection
```

Any import that crosses a layer boundary causes a Deptrac failure.

## Code Coverage

Code coverage is collected with `pcov` (faster than Xdebug):

```bash
vendor/bin/phpunit --testsuite=unit --coverage-html coverage/html/
```

Or text summary:

```bash
vendor/bin/phpunit --testsuite=unit --coverage-text
```

Coverage thresholds are not enforced by PHPUnit configuration directly; the mutation
testing MSI gate provides a stronger signal than line coverage alone.

## CI Pipeline

### `.github/workflows/ci.yml` (fast feedback)

Runs on every push and pull request:

1. `composer stan` — PHPStan
2. `composer psalm` — Psalm
3. `composer cs` — PHP CS Fixer dry-run
4. `composer deptrac` — architecture check
5. `composer rector` — Rector dry-run (no unapplied refactors)
6. `composer test` — unit tests
7. `composer test:golden` — golden-file tests

Matrix: PHP 8.4 only (minimum supported version).

### `.github/workflows/integration.yml` (integration + contract)

Runs on push to `main` and weekly:

1. Starts service containers for MySQL 8.0, MySQL 5.7, MariaDB 10.6, PostgreSQL 16,
   PostgreSQL 13, SQL Server 2022.
2. `composer test:integration`
3. `composer test:contract`

### Mutation Testing in CI

Infection runs as a separate optional job on `main` pushes:

```bash
composer infection
```

A failure here does not block merges but is tracked as a warning.

## Environment Variables for Integration Tests

Copy `.env.example` to `.env` and fill in the connection details for local integration
testing without Testcontainers:

```bash
SQLCRAFT_MYSQL_HOST=127.0.0.1
SQLCRAFT_MYSQL_PORT=3306
SQLCRAFT_MYSQL_DATABASE=sqlcraft_test
SQLCRAFT_MYSQL_USERNAME=root
SQLCRAFT_MYSQL_PASSWORD=

SQLCRAFT_PGSQL_HOST=127.0.0.1
SQLCRAFT_PGSQL_PORT=5432
SQLCRAFT_PGSQL_DATABASE=sqlcraft_test
SQLCRAFT_PGSQL_USERNAME=postgres
SQLCRAFT_PGSQL_PASSWORD=

SQLCRAFT_SQLITE_DATABASE=:memory:

SQLCRAFT_SQLSRV_HOST=127.0.0.1
SQLCRAFT_SQLSRV_PORT=1433
SQLCRAFT_SQLSRV_DATABASE=sqlcraft_test
SQLCRAFT_SQLSRV_USERNAME=sa
SQLCRAFT_SQLSRV_PASSWORD=
```

The Docker Compose file (`docker-compose.yml`) brings up all engines locally:

```bash
docker compose up -d
composer test:all
docker compose down
```
