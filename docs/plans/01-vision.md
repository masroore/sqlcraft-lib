# SQLCraft Planning — 01: Vision

> Status: **Planning phase. No code exists yet.**
> Last updated: 2026-07-20

---

## Long-Term Vision

SQLCraft should become the canonical PHP library for database-administration capabilities — the component that framework maintainers, IDE vendors, AI agent toolkits, and CLI tool authors reach for when they need to speak to a live database in an administrative capacity. Just as Flysystem ended filesystem adapter sprawl, and Doctrine DBAL ended raw-PDO proliferation for SQL abstraction, SQLCraft should end the pattern of every tool reimplementing schema introspection, DDL generation, and import/export from scratch against each engine.

The long-term bar: if a developer needs to write "list all tables in this database, with column metadata, across MySQL and PostgreSQL, in a Laravel app and a standalone CLI," the answer should be `composer require vendor/sqlcraft` and ten lines of code — not five hundred lines of engine-specific SQL.

---

## Design Goals

### 1. Framework Independence
SQLCraft has zero framework dependencies. It uses only PHP 8.4 + PDO + PSR interfaces (PSR-3 Logger, PSR-14 Events). A Laravel application may use its service container to wire SQLCraft's dependencies; SQLCraft itself never requires Laravel, never uses `app()`, never uses Symfony's Container, never reads `config()`. The same library artifact runs identically in all environments.

**Constraint derived from:** the pattern where Doctrine DBAL's `Connection` can be constructed with a plain array and a `Driver` object, with no framework bootstrap.

### 2. Capability-Driven Multi-Engine Support
SQLCraft models database engines not by a lowest-common-denominator API but by an explicit capability map. Each platform declares exactly which features it supports via a `Capability` enum. Code that depends on a capability declares that dependency explicitly; code that calls a missing capability receives a `CapabilityException` immediately, not silent wrong results.

No feature is silently degraded. If MySQL does not support deferrable foreign keys and the caller asks for one, the library throws `CapabilityException::featureNotSupported(Capability::DeferrableForeignKey, $platform)`. The caller decides how to handle the gap.

### 3. Immutability and Type Safety
All data returned from SQLCraft is a graph of readonly PHP 8.4 value objects. `TableStatus`, `ColumnDefinition`, `IndexDefinition`, `ForeignKeyDefinition`, `RoutineDefinition` are all `readonly` classes with typed constructor-promoted properties. They cannot be mutated after construction. This makes them safe to cache, safe to pass across layers, and trivially serializable.

Mutable *command* objects (create-table requests, alter-column requests) use a builder pattern that returns a new instance on each `with*` call, preserving the immutability contract while remaining ergonomic.

### 4. Streaming / Memory Efficiency
Exporting a 50 GB database must not load 50 GB into RAM. SQLCraft's export service uses PHP generators and chunked iteration throughout. Import likewise processes SQL/CSV files in configurable chunks. BLOB download is exposed as a `resource` handle, not a `string`. The API is designed so that streaming is the default, not an afterthought.

### 5. Explicit Contracts / Interface Segregation
Every service is introduced behind an interface. Consumers type-hint against interfaces, not concrete classes. This enables:
- Unit testing with stub/fake implementations
- Third-party driver implementations
- Future capability extensions without BC breaks

The interface hierarchy follows ISP rigorously: a read-only consumer does not receive an object that exposes `dropTable()`.

### 6. Extensibility Without Modification
Adding a new database driver (CockroachDB, ClickHouse, DuckDB) requires implementing three interfaces (`ConnectionInterface`, `PlatformInterface`, `DriverInterface`) and registering a capability map. No existing code changes. The extension point is a contract, not a subclass of a monolithic `Driver` class.

Export formats (JSON, Parquet, custom) are added by implementing `ExportFormatterInterface`. Import formats by implementing `ImportParserInterface`. These are registered into the services they belong to, not hard-coded.

---

## Non-Goals (and Why)

| Non-Goal | Reasoning |
|---|---|
| **ORM / Active Record** | Adding entity mapping would double the scope, create framework-incompatibility surface, and compete with Doctrine/Eloquent. SQLCraft's domain is administration, not application data modelling. |
| **Migration framework** | Versioned migration management (up/down, state table) is a separate concern already well-covered by Doctrine Migrations, Phinx, Liquibase. SQLCraft provides the DDL primitives those tools can call. |
| **Web UI / HTML rendering** | Any rendering concern couples the library to a specific output format and HTTP model, breaking framework independence. The caller owns the UI. |
| **Query builder for application models** | `SELECT * FROM users WHERE active = 1` is application code, not administration code. SQLCraft's query layer exists to support admin operations (data browse, inline edit, FK navigation), not to be a general-purpose query builder. |
| **Connection pool management** | Connection pooling (PgBouncer, ProxySQL, application-level) is infrastructure-specific. SQLCraft supports lazy connections and named connections, but does not manage pool lifecycles — that belongs to the application container. |
| **Authentication / session management** | Who is allowed to use SQLCraft in an application is an application concern, not a library concern. SQLCraft accepts explicit credentials per-connection; it never reads `$_SESSION`, `$_COOKIE`, or `$_SERVER`. |

---

## Target Personas

### Persona A — Framework App Developer
**Context:** Building a Laravel or Symfony admin panel. Needs a tab that lets support staff browse tables, run queries, export data.
**What They Need:** Typed `TableCollection`, `ColumnCollection`; safe parameterized data browse; export to CSV; all wired into Laravel's `ServiceProvider` in twenty lines.
**SQLCraft Value:** Provides all DB-admin operations without bundling a UI. The developer writes Blade/Twig; SQLCraft provides the data.

### Persona B — CLI Tool Author
**Context:** Writing a `db-admin` CLI command using symfony/console. No HTTP context.
**What They Need:** Schema introspection, DDL generation, streaming export to stdout or file, connection by DSN.
**SQLCraft Value:** Works with zero framework bootstrap. `new PdoConnection($dsn)` and call services directly.

### Persona C — AI Agent / LLM Tool
**Context:** An LLM agent that can call PHP functions to inspect a database, answer schema questions, or execute safe read-only SQL.
**What They Need:** Fast schema enumeration (`listTables()`, `describeTable()`), safe read-only query execution, structured return types that can be serialized to JSON.
**SQLCraft Value:** Typed VOs serialize cleanly. Capability checks prevent the agent from issuing unsafe DDL. A read-only `MetadataService` can be exposed without exposing `DDLService`.

### Persona D — IDE / Editor Plugin
**Context:** A VS Code extension providing database autocomplete, ERD diagrams, query execution.
**What They Need:** Fast table list, column types with lengths, FK graph, index list. Long-polling for schema changes.
**SQLCraft Value:** `MetadataService::getTableStatus()`, `::listColumns()`, `::listForeignKeys()` return typed objects an IDE plugin can consume directly.

### Persona E — REST / GraphQL API Backend
**Context:** A headless admin API that multiple frontend clients call.
**What They Need:** All DB admin operations exposed as service methods; results serializable; events for audit logging.
**SQLCraft Value:** Service classes are plain PHP objects with typed methods. The API layer calls them directly; PSR-14 events feed the audit log.

---

## Success Metrics

A v1.0 release is successful when:

1. **Implementable from docs alone:** A senior PHP engineer who has not seen SQLCraft's code can implement a new driver by reading `07-driver-platform.md` and `08-capability-model.md`. No tribal knowledge required.

2. **Cross-framework unmodified:** The same `composer.json` and the same service wiring (no framework-specific config) runs in Laravel 11, Symfony 7, Slim 4, raw PHP, and a PHPUnit test process.

3. **New driver = interfaces only:** Adding CockroachDB support requires zero changes to any existing class — only new classes implementing `ConnectionInterface`, `PlatformInterface`, `DriverInterface`, and a capability map entry.

4. **PHPStan max / Psalm max at baseline:** `vendor/bin/phpstan analyse --level=max` and `vendor/bin/psalm --show-info=true` both pass with zero issues on initial release.

5. **Memory-safe export:** Exporting a database larger than `memory_limit` completes without a fatal error. The export API uses generators throughout.

6. **Feature parity with Adminer on the five v1 engines:** Every operation listed in `04-feature-inventory.md` for MySQL, MariaDB, PostgreSQL, SQLite, and MSSQL is either implemented, or has a `Capability` entry explicitly marking it absent for that engine. Oracle is deferred.

---

## What "Done" Looks Like for v1.0

```php
// Framework-agnostic construction
$conn = PdoConnection::fromDsn('mysql:host=127.0.0.1;dbname=myapp', 'root', 'secret');
$driver = DriverRegistry::forConnection($conn);
$platform = $driver->getPlatform();

// Metadata
$meta = new MetadataService($conn, $driver);
$tables = $meta->listTables();          // TableCollection<TableStatus>
$columns = $meta->listColumns('users'); // ColumnCollection<ColumnDefinition>

// DDL
$ddl = new DDLService($conn, $driver, $platform);
$ddl->addColumn('users', new AddColumnCommand(
    name: 'email_verified_at',
    type: ColumnType::Timestamp,
    nullable: true,
));

// Export — streaming, no memory spike
$export = new ExportService($conn, $driver);
$export->dumpTable('users', new SqlExportFormatter(), $outputStream);

// Capability guard — explicit, typed
if (!$platform->supports(Capability::MaterializedViews)) {
    throw CapabilityException::featureNotSupported(Capability::MaterializedViews, $platform);
}
```

v1.0 ships with:
- All five v1 drivers (MySQL, MariaDB, PostgreSQL, SQLite, MSSQL)
- Full metadata introspection
- Full DDL for tables, columns, indexes, foreign keys, views
- Data CRUD + browse with pagination
- SQL execution service
- Import (SQL file, CSV, TSV)
- Export (SQL dump, CSV, TSV; gzip optional)
- User/privilege management
- PSR-14 event bus integration
- PHPStan max + Psalm max clean
- Integration test suite covering all five v1 engines via Docker
