# 07 — Module Breakdown

> **Status:** Design draft  
> **Scope:** Per-module deep dive for every bounded context  
> **Template:** Purpose → Key Interfaces & Classes → Public API surface → Inbound/Outbound deps → Adminer absorption → Extension points → Testing seams

---

## Module Template

Each section below follows this structure:

| Field | Content |
|-------|---------|
| **Purpose** | What problem this module solves |
| **Namespace root** | `SQLCraft\<Module>\` |
| **Key interfaces** | Named, one-line each |
| **Key classes** | Named, one-line each |
| **Public API surface** | What consumers (application services or external) touch |
| **Internal** | What must never leak past the module boundary |
| **Inbound deps** | What may call into this module |
| **Outbound deps** | What this module is allowed to call |
| **Absorbs from Adminer** | Adminer functionality relocated here |
| **Extension points** | How third parties extend without forking |
| **Testing seam** | What to mock/stub to unit-test this module in isolation |

---

## 1. Contracts

**Purpose:** The central port definitions. Every interface that crosses a module boundary lives here. Nothing concrete; no implementations. This is the dependency inversion anchor.

**Namespace root:** `SQLCraft\Contracts\`

**Key interfaces:**

| Interface | Purpose |
|-----------|---------|
| `ConnectionInterface` | Execute statements, quote values, return results |
| `DriverInterface` | Create connections, declare platform, build DSNs |
| `PlatformInterface` | Dialect rules: quoting, pagination, type mapping |
| `QuotingInterface` | Identifier and value quoting, sub-interface of Platform |
| `PaginationInterface` | LIMIT/OFFSET generation, sub-interface of Platform |
| `TypeMapperInterface` | PHP↔DB type mapping, sub-interface of Platform |
| `DdlDialectInterface` | DDL SQL generation hooks, sub-interface of Platform |
| `IntrospectionDialectInterface` | Per-engine metadata queries |
| `MetadataProviderInterface` | Returns typed DTOs from a live connection |
| `SchemaInspectorInterface` | High-level schema query API |
| `DdlBuilderInterface` | Builds DDL statements from VOs |
| `QueryBuilderInterface` | Fluent SQL construction (SELECT/INSERT/UPDATE/DELETE) |
| `ExecutorInterface` | Runs statements, returns `ExecutionResult` |
| `ImporterInterface` | Accepts a stream/file, writes to DB |
| `ExporterInterface` | Reads from DB, writes to a stream/file |
| `CapabilityResolverInterface` | Platform + version → `CapabilitySet` |
| `EventDispatcherInterface` | PSR-14-compatible dispatcher port |
| `SecurityGuardInterface` | Object-level privilege check |

**Public API surface:** Everything — this module IS the public API surface for the rest of the library.

**Internal:** Nothing. No implementation details live here.

**Inbound deps:** All modules import from Contracts.

**Outbound deps:** Only `SQLCraft\Exceptions\`, `SQLCraft\ValueObjects\`, `SQLCraft\DTO\`, `SQLCraft\Collections\`.

**Absorbs from Adminer:** The scattered signatures of `SqlDb`, `SqlDriver` methods, and free function contracts that Adminer never formally named as interfaces.

**Extension points:** Third-party modules implement these interfaces; no changes to Contracts needed.

**Testing seam:** All interfaces can be mocked. Test doubles defined here are usable by every other module's test suite.

---

## 2. ValueObjects

**Purpose:** Primitive domain types — the atoms of the domain model. All classes are `final readonly`. No DB calls, no I/O.

**Namespace root:** `SQLCraft\ValueObjects\`

**Key classes:**

| Class | Purpose |
|-------|---------|
| `Identifier` | Single unquoted DB object name |
| `QualifiedName` | catalog.schema.object tuple |
| `DataType` | Type name + length/precision/scale/unsigned/collation |
| `DefaultValue` | Discriminated union: null / empty-string / literal / expression / sequence-next |
| `ForeignKeyAction` | Enum: RESTRICT, CASCADE, SET_NULL, NO_ACTION, SET_DEFAULT |
| `TriggerTiming` | Enum: BEFORE, AFTER, INSTEAD_OF |
| `TriggerEvent` | Enum: INSERT, UPDATE, DELETE, TRUNCATE |
| `RoutineDirection` | Enum: IN, OUT, INOUT |
| `IndexType` | Enum: PRIMARY, UNIQUE, INDEX, FULLTEXT, SPATIAL, GIN, GIST, BRIN |
| `Charset` | Validated charset name |
| `Collation` | Collation identifier |
| `Engine` | Storage engine name (MySQL/MariaDB-specific) |
| `ServerVersion` | Parsed semantic version for capability resolution |
| `ConnectionParameters` | Structured DSN parameters (host, port, db, credentials, SSL, extras) |
| `Privilege` | Named privilege (SELECT, INSERT, etc.) for security modelling |

**Public API surface:** All classes; they are shared freely across all modules.

**Internal:** None — everything is public by nature of being a VO.

**Inbound deps:** Every module may use ValueObjects.

**Outbound deps:** `SQLCraft\Support\` only.

**Absorbs from Adminer:** `FieldType` (→ `DataType`), inline enum strings for triggers/FK-actions, `inout` string (→ `RoutineDirection`).

**Extension points:** Third-party code creates its own VOs. No extension of core VOs needed.

**Testing seam:** Pure value construction; no mocks needed. Property-based tests validate invariants.

---

## 3. DTO

**Purpose:** Immutable snapshots of database metadata returned by introspection. These are read-models — they carry data, not behaviour.

**Namespace root:** `SQLCraft\DTO\`

**Key classes:**

| Class | Adminer equivalent | Purpose |
|-------|--------------------|---------|
| `TableStatus` | `TableStatus` alias | Table-level metadata (engine, comment, row count, etc.) |
| `ColumnMeta` | `Field` alias | Single column definition snapshot |
| `IndexMeta` | `Index` alias | Index definition snapshot |
| `ForeignKeyMeta` | `ForeignKey` alias | Foreign key snapshot |
| `TriggerMeta` | `Trigger` alias | Trigger snapshot |
| `RoutineMeta` | `Routine` alias | Function/procedure snapshot |
| `RoutineParameter` | `RoutineField` alias | Single parameter of a routine |
| `ViewMeta` | n/a (Adminer has no VO) | View definition snapshot |
| `SequenceMeta` | n/a (PgSQL-specific) | Sequence definition snapshot |
| `DatabaseMeta` | n/a | Database-level metadata |
| `SchemaMeta` | n/a (PgSQL/MSSQL) | Named schema metadata |
| `UserMeta` | n/a | DB user/role snapshot |
| `ServerInfo` | `server_info` prop | Server version, charset, uptime |
| `PartitionInfo` | `Partitions` alias | Partition configuration snapshot |
| `BackwardKeyMeta` | `BackwardKey` alias | Reverse FK reference |
| `ProcessMeta` | processlist row | Single process/connection record |

**Public API surface:** All DTO classes — returned by Metadata services, Schema services, Execution results.

**Internal:** Nothing. DTOs are transparent data bags.

**Inbound deps:** Metadata, Schema, Export, Events modules consume DTOs.

**Outbound deps:** `SQLCraft\ValueObjects\`, `SQLCraft\Support\`.

**Absorbs from Adminer:** Every phpstan `@type` alias from `adminer.php` gets a concrete readonly class.

**Extension points:** Consumers may extend DTO classes if they need additional computed properties, but SQLCraft never depends on subclasses.

**Testing seam:** Construct directly in tests. No mocks needed.

---

## 4. Collections

**Purpose:** Typed, immutable iterable wrappers over arrays of VOs/DTOs. Prevent accidental passing of `array` where a typed collection is expected. PHPStan/Psalm `@template` annotations enable generic type checking.

**Namespace root:** `SQLCraft\Collections\`

**Key classes:**

| Class | Item type | Notes |
|-------|-----------|-------|
| `ColumnCollection` | `ColumnMeta` | Ordered; keyed by column name for O(1) lookup |
| `IndexCollection` | `IndexMeta` | |
| `ForeignKeyCollection` | `ForeignKeyMeta` | |
| `TableCollection` | `TableStatus` | Keyed by table name |
| `RoutineCollection` | `RoutineMeta` | |
| `TriggerCollection` | `TriggerMeta` | |
| `PrivilegeCollection` | `Privilege` | |
| `DatabaseCollection` | `DatabaseMeta` | |

All collections extend an `AbstractImmutableCollection` that implements `\IteratorAggregate`, `\Countable`, and `\ArrayAccess` (read-only).

**Generics pattern (PHPStan/Psalm template):**

```php
/** @template T of object */
abstract class AbstractImmutableCollection implements \IteratorAggregate, \Countable
{
    /** @param list<T> $items */
    public function __construct(protected readonly array $items) {}

    /** @return T */
    public function get(int|string $key): mixed { /* ... */ }

    /** @param \Closure(T): bool $predicate @return static */
    public function filter(\Closure $predicate): static { /* ... */ }

    /** @param \Closure(T): mixed $fn @return list<mixed> */
    public function map(\Closure $fn): array { /* ... */ }
}
```

**Rationale over plain arrays:** Plain `array` types are checked only at the point of annotation, not at runtime. A typed collection enforces homogeneity, provides named methods (`filter`, `map`, `get`), and is refactorable without grep.

**Lazy loading:** Collections are eager by default (introspection fetches everything). Deferred loading is provided via `LazyCollection` which wraps a `\Closure` producer — the collection materialises on first iteration. Application services return `LazyCollection` for large result sets (e.g., all tables in a database with thousands of tables).

**Testing seam:** Construct with a fixed array; no mocks needed.

---

## 5. Connection

**Purpose:** Wraps a PDO resource. Provides statement execution, quoting, and transaction control without leaking PDO types past this module boundary.

**Namespace root:** `SQLCraft\Connection\`

**Key interfaces/classes:**

| Name | Type | Purpose |
|------|------|---------|
| `ConnectionInterface` | Interface | Execute, quote, transaction control |
| `PdoConnection` | Final class | Concrete PDO wrapper |
| `ConnectionFactory` | Final class | Creates `PdoConnection` from `ConnectionParameters` via a `DriverInterface` |
| `ConnectionPool` | Final class | Simple pool for applications needing concurrent connections |
| `TransactionManager` | Final class | Savepoint-aware transaction nesting |
| `StatementResult` | Readonly DTO | Raw result rows + affected count + last-insert-id |
| `QueryLogger` | Interface | Deferred optional PSR-3-compatible query log hook; not part of the v1 public contract |

**Key interface sketch:**

```php
interface ConnectionInterface
{
    public function execute(string $sql, array $bindings = []): StatementResult;
    public function quote(string $value): string;
    public function quoteIdentifier(string $name): string;
    public function beginTransaction(): void;
    public function commit(): void;
    public function rollback(): void;
    public function inTransaction(): bool;
    public function getServerVersion(): ServerVersion;
    public function getDatabaseName(): string;
    public function ping(): bool;
    public function close(): void;
}
```

**Public API:** `ConnectionInterface`, `ConnectionFactory`, `ConnectionPool`, `TransactionManager`.

**Internal:** `PdoConnection`, PDO object itself. The PDO instance is private; it never surfaces past `PdoConnection`.

**Inbound deps:** Application services via `ConnectionInterface`; Metadata, DDL, Query, Execution modules.

**Outbound deps:** `Contracts`, `ValueObjects`, `Exceptions`, `Events` (query-executed event).

**Absorbs from Adminer:** `SqlDb::query()`, `SqlDb::multi_query()`, `PdoDb::query()` — now a typed interface with explicit binding.

**Extension points:** Implement `ConnectionInterface` to provide a mock, proxy, or async connection.

**Testing seam:** Mock `ConnectionInterface`; `PdoConnection` is tested with integration tests against a real DB.

---

## 6. Driver

**Purpose:** Connection factories and DSN builders for each supported engine. Selects the correct platform for a given connection.

**Namespace root:** `SQLCraft\Driver\`

**Key classes:**

| Name | Type | Purpose |
|------|------|---------|
| `DriverRegistry` | Final class | Instance-backed registry; third parties register drivers at bootstrap |
| `MySQLDriver` | Final class | MySQL/MariaDB connection factory |
| `PostgreSQLDriver` | Final class | PostgreSQL connection factory |
| `SqliteDriver` | Final class | SQLite connection factory |
| `SqlServerDriver` | Final class | MS SQL Server connection factory |
| `AbstractDriver` | Abstract class | Deferred optional helper; built-in drivers implement the interface directly |
| `ConnectionParameters` | Readonly VO | Structured DSN fields (host, port, db, user, pass, ssl, extras) |
| `DriverNotFoundException` | Exception | Unknown driver name requested |

**Public API:** `DriverRegistry`, `ConnectionParameters`. `DriverRegistry` is instance-based; construct it with built-in or consumer-provided drivers.

**Internal:** Concrete driver classes (callers work via `DriverInterface`).

**Inbound deps:** `ConnectionFactory` (in Connection module); application bootstrap/DI containers.

**Outbound deps:** `Contracts`, `Connection`, `Platform`, `Capabilities`, `ValueObjects`, `Exceptions`.

**Absorbs from Adminer:** Global `$driver`, `$_GET['driver']` selection, `PdoDb` connect logic.

**Extension points:** Third-party packages implement `DriverInterface` and call `$registry->register($driver)` on their injected `DriverRegistry` instance.

**Testing seam:** Implement a `FakeDriver` returning a `MockConnection` for unit tests.

---

## 7. Platform

**Purpose:** SQL dialect knowledge — identifier quoting, pagination, type mapping, DDL fragments, introspection SQL. The per-engine brain.

**Namespace root:** `SQLCraft\Platform\`

**Key classes:**

| Name | Type | Purpose |
|------|------|---------|
| `AbstractPlatform` | Abstract class | Shared SQL defaults; template-method hooks |
| `MySQLPlatform` | Final class | MySQL dialect (backtick quoting, LIMIT/OFFSET, MySQL type set) |
| `MariaDbPlatform` | Final class | Extends MySQLPlatform; MariaDB-specific capability overrides |
| `PostgreSQLPlatform` | Final class | PgSQL dialect ($n params, schemas, bytea, sequences) |
| `SqlitePlatform` | Final class | SQLite dialect (limited DDL, rowid, no procedures) |
| `SqlServerPlatform` | Final class | MSSQL dialect ([bracket] quoting, TOP pagination, schemas) |
| `PlatformCapabilityResolver` | Final class | Evaluates version predicates; produces `CapabilitySet` |

**Public API:** All platform classes (via `PlatformInterface`); `PlatformCapabilityResolver`.

**Inbound deps:** Driver module (selects platform); application services via `PlatformInterface`.

**Outbound deps:** `Contracts`, `ValueObjects`, `DTO`, `Capabilities`, `Exceptions`, `Support`.

**Absorbs from Adminer:** `SqlDriver` class (quoting, pagination, type maps, `support()` flag set).

**Extension points:** Implement `PlatformInterface` for a new engine; or extend `AbstractPlatform` for minimal delta.

**Testing seam:** Instantiate a concrete platform directly (no DB needed); test quoting, pagination SQL generation.

---

## 8. Metadata

**Purpose:** Introspect a live database and return typed DTOs. The read side of the DB administration API.

**Namespace root:** `SQLCraft\Metadata\`

**Key interfaces/classes:**

| Name | Type | Purpose |
|------|------|---------|
| `MetadataServiceInterface` | Interface | High-level introspection API |
| `MetadataService` | Final class | Orchestrates introspection: calls platform SQL, runs via connection, hydrates via factory |
| `MetadataFactoryInterface` | Interface | Row array → typed DTO |
| `MySQLMetadataFactory` | Final class | MySQL/MariaDB row hydration |
| `PostgreSQLMetadataFactory` | Final class | PgSQL row hydration |
| (etc. per platform) | | |

**Public API:** `MetadataServiceInterface` — returns `TableCollection`, `ColumnCollection`, `IndexCollection`, etc.

**Inbound deps:** Schema, Export, application code.

**Outbound deps:** `Contracts`, `DTO`, `Collections`, `ValueObjects`, `Exceptions`, `Support`.

**Absorbs from Adminer:** Free functions `tables()`, `fields()`, `indexes()`, `foreign_keys()`, `triggers()`, `routines()`, `sequences()`, `user_privileges()`.

**Extension points:** Implement `MetadataServiceInterface` for a custom introspection strategy (e.g., cached).

**Testing seam:** Mock `ConnectionInterface` to return fixture rows; test hydration logic.

---

## 9. Schema, DDL, Query, Execution

**Schema:** Compares two `TableStatus`/`ColumnCollection` snapshots and produces a diff (list of `AlterOperation` VOs). Used by migration tools and schema-synchronisation features. Depends on Metadata, DTO, ValueObjects.

**DDL:** Converts VOs (ColumnMeta + DataType + constraints) into DDL SQL strings via a `DdlBuilderInterface`. Platform-aware via `DdlDialectInterface` injection. Returns SQL strings; does not execute. Depends on Contracts, ValueObjects, Platform, Capabilities.

**Query:** Fluent query builder returning SQL strings (SELECT/INSERT/UPDATE/DELETE). No execution. Bound-parameter aware (returns `PreparedStatement { sql: string, bindings: array }`). Depends on Contracts, ValueObjects, Platform.

**Execution:** Runs a SQL string (or `PreparedStatement`) via a `ConnectionInterface`, emits events, returns `ExecutionResult`. The only module that calls `ConnectionInterface::execute()` on behalf of application code. Depends on Contracts, DTO, Collections, Events, Exceptions.

---

## 10. Import / Export / Security / Events / Support / Utilities

**Import:** Accepts a readable stream and a format (`sql`, `csv`, `json`). Parses it into statements/rows and feeds to Execution. Emits `ImportProgressEvent` periodically. Depends on Contracts, Execution, DTO, Exceptions.

**Export:** Accepts a `ConnectionInterface` + scope (database, table, query result) + format and writes to a writable stream. Depends on Contracts, Execution, Metadata, DTO, Collections, Exceptions.

**Security:** Models `Privilege` VOs, user/role structures, and a `SecurityGuard` that evaluates whether a user may perform an action. No HTTP/session; purely data + logic. Depends on Contracts, ValueObjects, Exceptions.

**Events:** `final readonly` event classes + `EventDispatcherInterface` (thin PSR-14 port). No listener implementations — those are consumer-provided. Depends on Contracts, ValueObjects, DTO.

**Exceptions:** Exception class hierarchy only. `final` classes; typed properties. Depends on Contracts, ValueObjects.

**Support:** Pure utility functions: `StringUtil`, `TypeUtil`, `ArrayUtil`, and `SecretRedactor` for credential-safe diagnostic text. No domain logic, no DB. Depends on nothing.

**Utilities:** Higher-level helpers: `PaginationCalculator` (page/total → offset/limit), `IdentifierSanitizer` (strips dangerous characters from user input before it reaches an `Identifier` VO). Depends on Support only.

---

## 11. Module Dependency Table

```
Module          → Depends On
─────────────────────────────────────────────────────────────────────
Contracts       → (nothing)
Support         → (nothing)
Utilities       → Support
ValueObjects    → Support
DTO             → ValueObjects, Support
Collections     → ValueObjects, DTO, Support
Exceptions      → Contracts, ValueObjects
Capabilities    → Contracts, ValueObjects, Exceptions
Events          → Contracts, ValueObjects, DTO
Security        → Contracts, ValueObjects, Exceptions
Connection      → Contracts, ValueObjects, Exceptions, Events, Support
Platform        → Contracts, ValueObjects, DTO, Collections, Capabilities, Exceptions, Support
Driver          → Contracts, Connection, Platform, Capabilities, ValueObjects, Exceptions
Metadata        → Contracts, DTO, Collections, ValueObjects, Exceptions, Support
Schema          → Contracts, Metadata, DTO, ValueObjects, Collections, Exceptions
DDL             → Contracts, ValueObjects, Platform, Capabilities, Exceptions
Query           → Contracts, ValueObjects, Platform, Support, Exceptions
Execution       → Contracts, DTO, Collections, Events, Exceptions
Import          → Contracts, Execution, DTO, ValueObjects, Exceptions
Export          → Contracts, Execution, Metadata, DTO, Collections, Exceptions
─────────────────────────────────────────────────────────────────────
ADAPTER (wired at composition root, never imported by services above):
Driver, Platform, Connection concrete classes
```

**Enforcement:** Use Deptrac (`deptrac.yaml`) or PHPStan's `allowedToDepend` rule set to fail CI if a service imports a concrete adapter class.

