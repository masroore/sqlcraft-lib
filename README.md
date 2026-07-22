# SQLCraft

SQLCraft is a framework-independent PHP 8.4+ SDK for database administration.
It provides typed connection, metadata, DDL, query, import, export, capability,
and event primitives while keeping PDO, HTTP, UI, and application state out of
consumer code.

## Status

SQLCraft is in the v1.0 release-candidate phase. The supported platforms are
SQLite, MySQL, MariaDB-compatible MySQL deployments, PostgreSQL, and Microsoft
SQL Server. Oracle support is intentionally deferred to a future milestone and
is not part of this release.

## Installation

```bash
composer require vendor/sqlcraft
```

SQLCraft requires `ext-pdo`. Install the PDO extension for each engine you plan
to use:

- `ext-pdo_sqlite` for SQLite
- `ext-pdo_mysql` for MySQL and MariaDB
- `ext-pdo_pgsql` for PostgreSQL
- `ext-pdo_sqlsrv` or `ext-pdo_dblib` for Microsoft SQL Server

The package does not require a framework or PSR implementation at runtime.
Optional PSR event, logging, and cache integrations are suggested dependencies.

## Quickstart: SQLite

The following complete script creates an in-memory database, inserts a row, and
streams the result through SQLCraft's connection boundary:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Driver\SqliteDriver;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\ConnectionParameters;

$connectionFactory = new PdoConnectionFactory(new PdoExceptionTranslator());
$driver = new SqliteDriver($connectionFactory, new SqlitePlatform());
$database = $driver->connect(new ConnectionParameters(database: ':memory:'));

$database->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
$database->execute('INSERT INTO users (name) VALUES (?)', ['Ada']);

foreach ($database->query('SELECT id, name FROM users', streaming: true) as $row) {
    printf("%d: %s\n", $row['id'], $row['name']);
}
```

Run the same script from the repository with:

```bash
php examples/01-basic-connection.php
```

Every numbered example is runnable after `composer install` and uses SQLite by
default, so no engine container or external service is required:

- `examples/01-basic-connection.php` — connect, write, and stream rows
- `examples/02-schema-introspection.php` — inspect tables and columns
- `examples/03-ddl-create-table.php` — render and execute a DDL builder
- `examples/04-query-and-paginate.php` — allowlisted filtering and pagination
- `examples/05-import-export.php` — streaming row export and import
- `examples/06-laravel-integration.php` — thin service-provider binding shape
- `examples/07-symfony-integration.php` — thin service-definition shape
- `examples/08-multi-engine-comparison.php` — engine-independent operation shape
- `examples/09-transactions.php` — commit and rollback on a balance transfer
- `examples/10-alter-table.php` — render alter-table DDL
- `examples/11-create-index.php` — render create/drop index DDL
- `examples/12-structured-export.php` — SQL export via Exporter + SqlFormatWriter
- `examples/13-csv-import.php` — CSV import into a table
- `examples/14-event-hooks.php` — PSR-14 query listeners
- `examples/15-credential-providers.php` — array and env credential providers
- `examples/16-connection-manager.php` — named multi-connection registry
- `examples/17-capability-detection.php` — platform capability checks
- `examples/18-export-formats.php` — JSON/XML/XLSX/HTML export (writes `examples/out/`)

## Architecture

SQLCraft follows a small hexagonal boundary:

1. `DriverInterface` owns engine-specific DSN construction and platform choice.
2. `ConnectionInterface` owns database I/O; PDO types never leave `SQLCraft\\Connection`.
3. `PlatformInterface` owns quoting, capability checks, SQL rendering, and engine dialect.
4. `SchemaManager`, DDL builders, `QueryExecutor`, and import/export services use
   typed contracts above the connection layer.
5. PSR-14 events, when supplied, observe and intercept operations without adding
   a framework dependency.

For applications that need a container, register the driver registry and typed
connection factory in the container. Laravel and Symfony should bind SQLCraft
alongside their native database services, not replace them. The integration
shapes are shown in the two framework examples.

## Streaming and buffering

Query execution is streaming by default in `QueryExecutor::query()`, limiting
memory use for large result sets. Use the explicit `buffered: true` escape hatch
when random access, `count()`, or a fully materialized result is required.
Import and export services process data in chunks and expose progress events.

## Security model

SQLCraft separates values from SQL parameters and validates identifiers,
operators, aggregate functions, data types, pagination limits, statement counts,
and import sizes at their boundaries. User-controlled values are never
interpolated into rendered query SQL. Credentials are marked sensitive and
redacted from DSNs, logs, and exception text.

Capability checks are explicit:

```php
if (!$database->getPlatform()->has(\SQLCraft\Capabilities\Capability::Trigger)) {
    // Select a documented fallback instead of issuing unsupported DDL.
}
```

Use `require()` when an operation cannot continue without a capability; it
throws `SQLCraft\\Capabilities\\CapabilityNotSupportedException` with typed
capability, platform, and version context.

## Development

```bash
cp .env.example .env
docker compose build php
docker compose run --rm php composer install
docker compose run --rm php composer run ci
```

The `php` service is deliberately independent of engine services. M0–M1 checks
run with only the PHP container. Start database services separately for
integration tests from M2 onward:

```bash
docker compose up -d
docker compose run --rm php composer run test:integration
```

Oracle is deferred; no Oracle service is required for the default development
or CI workflow.

## Design documentation

The complete implementation design lives in [`docs/plans/`](docs/plans/):

- [`23-roadmap.md`](docs/plans/23-roadmap.md) — milestone goals and acceptance gates
- [`18-public-api.md`](docs/plans/18-public-api.md) — consumer workflows and API policy
- [`19-package-structure.md`](docs/plans/19-package-structure.md) — package layout and tooling
- [`20-testing.md`](docs/plans/20-testing.md) — unit, integration, contract, and golden tests
- [`21-performance.md`](docs/plans/21-performance.md) — streaming and query-count guarantees
- [`25-final-review.md`](docs/plans/25-final-review.md) — resolved design decisions and hard edges

## License

SQLCraft is released under the [MIT License](LICENSE).
