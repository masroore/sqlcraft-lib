# 08 — Driver Architecture

> **Status:** Design draft  
> **Scope:** DriverInterface, PlatformInterface (and segregated sub-interfaces), AbstractPlatform, concrete platforms, flavor handling, version-aware capabilities, metadata provider, DDL generator, driver registry, multi-connection support  
> **This is the most implementation-critical architecture document.**

---

## 1. Overview

The driver subsystem is SQLCraft's seam between the abstract domain model and the concrete reality of six (initially) incompatible database engines. Its design goals are:

1. **No engine assumption in application services.** A `SchemaInspector` using a MySQL connection and one using a PostgreSQL connection call the same interfaces.
2. **Segregated interfaces.** No driver is forced to implement dialect features it does not have. A hypothetical read-only analytics driver does not implement `DdlDialectInterface`.
3. **Version-aware capability negotiation.** A `MySQLPlatform` connected to 5.7 behaves differently from one connected to 8.0.16+ without branching in application services.
4. **Minimal new-driver surface.** Adding DuckDB should require implementing a bounded set of interfaces, with AbstractPlatform covering shared SQL fragments.
5. **Multi-connection support.** Adminer supports one active driver at a time via a global. SQLCraft supports N simultaneous connections to N different engines.

---

## 2. DriverInterface

The driver is a **connection factory**. It knows how to build a PDO DSN, create a `Connection`, and declare which `Platform` the connection operates on.

```php
namespace SQLCraft\Contracts\Driver;

interface DriverInterface
{
    /**
     * Build a PDO DSN string from structured parameters.
     * Never called by application code — used internally by ConnectionFactory.
     */
    public function buildDsn(ConnectionParameters $params): string;

    /**
     * Create and return an open Connection.
     * Wraps PDO internally; PDO never surfaces past this call.
     */
    public function connect(ConnectionParameters $params): ConnectionInterface;

    /**
     * Return the Platform implementation for this driver.
     * May be version-aware once the connection is open.
     */
    public function getPlatform(ConnectionInterface $connection): PlatformInterface;

    /**
     * Short canonical name used for driver registration ("mysql", "pgsql", "sqlite").
     */
    public function getName(): string;

    /**
     * Supported PDO driver string(s) — for compatibility checks.
     * @return list<string>
     */
    public function getPdoDriverNames(): array;
}
```

**Decision — DriverInterface vs abstract class:** Interface only. A `AbstractDriver` helper is provided but optional. Third-party drivers are not forced to extend an SQLCraft class, which would couple them to internal changes.

**Decision — ConnectionParameters:** A readonly VO carrying host, port, database, username, password, charset, ssl options, driver-specific extras. Never a raw DSN string — that is the driver's job to build.

---

## 3. PlatformInterface — Segregated

Adminer's `SqlDriver` is one god class with all dialect knowledge. SQLCraft splits this into segregated interfaces (Interface Segregation Principle). Drivers implement only what they support; application code depends only on what it needs.

### 3.1 `QuotingInterface`

```php
namespace SQLCraft\Contracts\Platform;

interface QuotingInterface
{
    /** Quote an identifier (table/column/schema name). */
    public function quoteIdentifier(Identifier $identifier): string;

    /** Quote a scalar value for safe embedding. Always prefers bind params; use for logging only. */
    public function quoteValue(mixed $value): string;

    /** Encode binary data as a DB-safe literal (0x hex, bytea, etc.). */
    public function quoteBinary(string $bytes): string;

    /** Apply convert expression for binary storage (DB-specific). */
    public function convertFieldIn(ColumnMeta $column, string $expression): string;

    /** Reverse convert expression when reading binary from DB. */
    public function convertFieldOut(ColumnMeta $column, string $expression): string;
}
```

**Adminer equivalents:** `idf_escape()`, `table()`, `q()`, `quoteBinary()`, `convertField()`, `unconvertField()`.

### 3.2 `PaginationInterface`

```php
interface PaginationInterface
{
    /**
     * Wrap a SELECT statement with pagination.
     * Returns a complete SQL string (MySQL: LIMIT/OFFSET; MSSQL: OFFSET...FETCH; Oracle: rownum).
     */
    public function applyPagination(string $sql, int $limit, int $offset): string;

    /**
     * Wrap a SELECT statement to return at most one row.
     * Used for single-row edits/deletes (MySQL: LIMIT 1; PgSQL: ctid subquery).
     */
    public function applySingleRowLimit(string $sql, string $whereClause): string;
}
```

### 3.3 `TypeMapperInterface`

```php
interface TypeMapperInterface
{
    /** Map a PHP value to the DB type token used in CREATE TABLE / ALTER. */
    public function mapPhpTypeToDb(string $phpType): string;

    /** Return all supported column type names for this platform. */
    public function getSupportedTypes(): array;

    /** Return unsigned-capable numeric types (MySQL/MariaDB only; others return []). */
    public function getUnsignedTypes(): array;

    /** Return collatable types (VARCHAR etc.). */
    public function getCollatableTypes(): array;
}
```

### 3.4 `PlatformInterface` (composite)

```php
interface PlatformInterface extends
    QuotingInterface,
    PaginationInterface,
    TypeMapperInterface,
    DdlDialectInterface,
    IntrospectionDialectInterface
{
    public function getName(): string;               // 'mysql', 'pgsql', etc.
    public function getFlavor(): ?string;            // 'mariadb', 'cockroach', null
    public function getServerVersion(ConnectionInterface $conn): ServerVersion;
    public function getCapabilitySet(ServerVersion $version): CapabilitySet;
    public function getDefaultCharset(): ?string;
    public function getDefaultCollation(): ?string;
    public function supportsSchemas(): bool;         // shorthand; same as capability check
    public function getKeywordList(): array;         // reserved words for identifier quoting hints
}
```

---

## 4. `AbstractPlatform`

Provides default implementations shared across platforms, reducing repetition in concrete classes.

```php
namespace SQLCraft\Platform;

abstract class AbstractPlatform implements PlatformInterface
{
    /** Default: double-quote wrapping (SQL standard). MySQL overrides with backticks. */
    public function quoteIdentifier(Identifier $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier->name) . '"';
    }

    /** Default single-row limit: LIMIT 1 clause appended. Platforms that cannot use LIMIT override. */
    public function applySingleRowLimit(string $sql, string $whereClause): string
    {
        return $sql . ' LIMIT 1';
    }

    /** Template method: subclasses declare their static capability matrix. */
    abstract protected function buildCapabilityMatrix(): array;

    public function getCapabilitySet(ServerVersion $version): CapabilitySet
    {
        return (new PlatformCapabilityResolver($this->buildCapabilityMatrix()))
            ->resolve($this->getName(), $version);
    }
}
```

**Template method pattern:** `buildCapabilityMatrix()` is the single hook subclasses must implement. The base class handles the resolver plumbing. This avoids each platform reimplementing the resolver.

---

## 5. Concrete Platforms

| Class | Flavor flag | Notes |
|-------|-------------|-------|
| `MySQLPlatform` | `mysql` | MySQL 5.7 – 9.x; backtick quoting; LIMIT/OFFSET |
| `MariaDbPlatform` | `maria` (extends MySQLPlatform) | Inherits MySQL but overrides check-constraint version gate, sequence support (10.3+), JSON type |
| `PostgreSQLPlatform` | `pgsql` | Double-quote identifiers; `$n` positional params; `OFFSET/FETCH`; schemas; sequences; materialized views |
| `SqlitePlatform` | `sqlite` | Double-quote identifiers; no server version concept; LIMIT/OFFSET; no stored procedures |
| `SqlServerPlatform` | `sqlserver` | `[bracket]` identifier quoting; `TOP n` pagination; schemas; view triggers |
| `OraclePlatform` | `oracle` | Double-quote identifiers; rownum subquery; CONNECT BY; no triggers on VOs |

---

## 6. Flavor Handling

**The problem:** MariaDB uses the MySQL PDO driver and responds to many MySQL-targeted queries, but has distinct features (sequences at 10.3+, JSON type differences, check constraints at 10.2.1+). Adminer detects MariaDB via a flavor flag in the connection object.

**Options considered:**

| Option | Pros | Cons |
|--------|------|------|
| Subclass (`MariaDbPlatform extends MySQLPlatform`) | Overrides only differences; IDE-navigable | Inheritance can become deep; subclass can't be swapped at runtime |
| Flavor flag in `MySQLPlatform` | Runtime switchable; single class to maintain | Conditional branches inside one class; grows complex |
| Separate class, no inheritance | Full isolation | Significant duplication of MySQL-identical code |

**Decision — subclass with flavor flag:** `MariaDbPlatform extends MySQLPlatform` with `getFlavor(): string { return 'maria'; }`. The base `MySQLPlatform` checks `$this->getFlavor() === 'maria'` only in capability matrix construction and version gate predicates — not in every method. This keeps branching contained.

**CockroachDB** extends `PostgreSQLPlatform` with flavor `'cockroach'`. It overrides specific introspection queries that CockroachDB implements differently and removes capabilities PgSQL has but Cockroach lacks (e.g., `Capability::Sequence` in older versions).

---

## 7. Version-Aware Capability Integration

Each `AbstractPlatform::buildCapabilityMatrix()` returns a structure like:

```php
// MySQLPlatform
protected function buildCapabilityMatrix(): array
{
    return [
        // Always-on for MySQL
        'always' => [
            Capability::Table, Capability::View, Capability::Columns, Capability::Indexes,
            Capability::ForeignKeys, Capability::Sql, Capability::Database, Capability::DropColumn,
            Capability::Comment, Capability::Charset, Capability::Collation, Capability::Status,
            Capability::Variables, Capability::Processlist, Capability::Kill, Capability::Privileges,
            Capability::Trigger, Capability::Routine, Capability::Procedure, Capability::Event,
            Capability::Copy, Capability::MoveColumn, Capability::Dump, Capability::InsertUpdate,
        ],
        // Version-gated (minimum version required)
        'versioned' => [
            [Capability::CheckConstraints,  [8, 0, 16]],
            [Capability::DescendingIndexes, [8, 0, 0]],
        ],
    ];
}
```

The `PlatformCapabilityResolver` reads this matrix and evaluates version predicates against the live `ServerVersion`.

---

## 8. Driver Registry and Discovery

```php
namespace SQLCraft\Driver;

final class DriverRegistry
{
    private static array $drivers = [];

    /** Called at bootstrap (e.g., in a ServiceProvider or factory). */
    public static function register(DriverInterface $driver): void
    {
        self::$drivers[$driver->getName()] = $driver;
    }

    public static function get(string $name): DriverInterface
    {
        return self::$drivers[$name]
            ?? throw DriverNotFoundException::forName($name);
    }

    /** @return list<string> */
    public static function getRegisteredNames(): array
    {
        return array_keys(self::$drivers);
    }
}
```

**Multi-connection support:** Unlike Adminer's global `$driver`, the registry is a static factory only. Each `connect()` call returns a fresh `ConnectionInterface`; applications hold references to multiple connections simultaneously. No global mutable state.

**Built-in auto-registration:** The `SQLCraftFactory` (or a DI container binding) pre-registers the 6 built-in drivers. Third parties call `DriverRegistry::register(new DuckDbDriver())` in their own ServiceProvider.

**Adminer comparison:** Adminer selects a driver via `$_GET['server']` and a global. SQLCraft allows N connections from N drivers to be active simultaneously — required for cross-database operations, migration tools, and AI agents comparing two DB instances.

---

## 9. Step-by-Step: Adding a New Driver (DuckDB Example)

DuckDB is an in-process OLAP database with a PHP PDO extension (`pdo_duckdb`). This walkthrough proves minimal work.

**Step 1: Create the ValueObject extension (if needed)**
DuckDB has array and struct types. Add `DuckDbArrayType extends DataType` in the consumer's namespace — or submit a PR to add it to `ValueObjects`. No SQLCraft core changes needed for the driver itself.

**Step 2: Implement `DriverInterface`**
```php
namespace Acme\SQLCraftDuckDb;

final class DuckDbDriver implements DriverInterface
{
    public function buildDsn(ConnectionParameters $params): string
    {
        return 'duckdb:' . ($params->database ?? ':memory:');
    }

    public function connect(ConnectionParameters $params): ConnectionInterface
    {
        $pdo = new \PDO($this->buildDsn($params), options: [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        return new PdoConnection($pdo, $this->getPlatform(...));
    }

    public function getPlatform(ConnectionInterface $connection): PlatformInterface
    {
        return new DuckDbPlatform();
    }

    public function getName(): string { return 'duckdb'; }
    public function getPdoDriverNames(): array { return ['duckdb']; }
}
```

**Step 3: Implement `DuckDbPlatform extends AbstractPlatform`**
Override only what differs from SQL-standard defaults. DuckDB uses double-quote identifiers (already the `AbstractPlatform` default). Override:
- `applyPagination()` — DuckDB supports `LIMIT n OFFSET m` (same as default, no override needed)
- `getSupportedTypes()` — DuckDB has HUGEINT, LIST, STRUCT, MAP, etc.
- `buildCapabilityMatrix()` — DuckDB has no triggers, routines, or users in the SQL sense

**Step 4: Implement `IntrospectionDialectInterface`**
DuckDB exposes `INFORMATION_SCHEMA`. Implement `fetchColumns()`, `fetchIndexes()`, etc. using DuckDB's schema views.

**Step 5: Register**
```php
DriverRegistry::register(new DuckDbDriver());
$conn = SQLCraftFactory::connect('duckdb', new ConnectionParameters(database: ':memory:'));
```

**Total new code:** ~4 classes. SQLCraft core untouched. The capability matrix limits what application services offer — no UI logic to update, no platform checks scattered in services.

---

## 10. `IntrospectionDialectInterface`

```php
namespace SQLCraft\Contracts\Platform;

interface IntrospectionDialectInterface
{
    /** SQL to list databases/catalogs. */
    public function getDatabasesSql(): string;

    /** SQL to list tables in the current database (+ schema if supported). */
    public function getTablesSql(string $database, ?string $schema = null): string;

    /** SQL to describe columns of a table. */
    public function getColumnsSql(QualifiedName $table): string;

    /** SQL to list indexes on a table. */
    public function getIndexesSql(QualifiedName $table): string;

    /** SQL to list foreign keys on a table. */
    public function getForeignKeysSql(QualifiedName $table): string;

    /** SQL to list triggers on a table (returns empty string if Trigger capability absent). */
    public function getTriggersSql(QualifiedName $table): string;

    /** SQL to list routines/procedures. */
    public function getRoutinesSql(?string $schema = null): string;

    /** SQL to list sequences (returns empty string if Sequence capability absent). */
    public function getSequencesSql(?string $schema = null): string;

    /** SQL to show server variables (returns empty string if Variables capability absent). */
    public function getVariablesSql(): string;

    /** SQL to list running processes (returns empty string if Processlist capability absent). */
    public function getProcesslistSql(): string;
}
```

Each platform's introspection SQL is fully encapsulated here. `MetadataService` never hardcodes engine-specific SQL — it calls `$platform->getColumnsSql($table)` and executes the result via `$connection->execute($sql)`.

---

## 11. `DdlDialectInterface`

Defined here as a contract; full DDL generation detail is covered in a future DDL-focused document. The interface exposes the per-platform SQL fragment hooks that `DdlBuilder` (Query/DDL module) composes into full statements:

```php
interface DdlDialectInterface
{
    public function renderColumnDefinition(ColumnMeta $column): string;
    public function renderPrimaryKeyClause(IndexMeta $index): string;
    public function renderForeignKeyClause(ForeignKeyMeta $fk): string;
    public function renderCheckConstraintClause(CheckConstraint $check): string;
    public function renderCreateTableStatement(QualifiedName $table, array $columnClauses, array $constraintClauses, array $tableOptions): string;
    public function renderAlterTableAddColumn(QualifiedName $table, ColumnMeta $column): string;
    public function renderAlterTableDropColumn(QualifiedName $table, Identifier $column): string;
    public function renderCreateIndexStatement(QualifiedName $table, IndexMeta $index): string;
    public function renderDropIndexStatement(QualifiedName $table, Identifier $indexName): string;
}
```

Each concrete platform implements the fragments that differ (e.g., MySQL's `ENGINE=InnoDB` table option vs PostgreSQL's `WITH (fillfactor=...)`), while `AbstractPlatform` provides SQL-standard defaults for the rest.

---

## 12. Summary of Key Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Interface granularity | Segregated (Quoting/Pagination/TypeMapper/Ddl/Introspection) | Drivers implement only what applies; ISP compliance |
| MariaDB vs MySQL | Subclass + flavor flag | Minimal duplication, contained branching |
| CockroachDB vs PostgreSQL | Subclass + flavor flag | Same reasoning as MariaDB |
| Capability gating | Version-predicate matrix per platform | Fast, testable, no live-DB dependency for resolution |
| Driver registration | Static registry, no globals | Multi-connection support, no session-scoped state |
| PDO exposure | Never past `Connection` adapter | Enforces the hexagonal boundary; swappable transport later (e.g., async) |
| Abstract base class | Optional (`AbstractPlatform`, `AbstractDriver`) | Reduces boilerplate without forcing inheritance dependency for third parties |

