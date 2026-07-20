# 05 — Domain Model

> **Status:** Design draft  
> **Scope:** Value Objects, DTOs, Collections, Services, Factories, Events, Exceptions, identity model, domain glossary  
> **Replaces:** Adminer's loose phpstan typeAliases (arrays), free functions, SqlDriver type maps

---

## 1. Guiding Principles

SQLCraft's domain model is deliberately thin on mutable state. The database server is the single authoritative aggregate root; SQLCraft holds no durable application state. Every artefact the library produces is therefore either:

- a **Value Object (VO)** — fully immutable, equality by value, created once and discarded or replaced; or
- a **Data Transfer Object (DTO) / read-model** — immutable snapshot of metadata fetched from the server; or
- a **stateless service** — a named behaviour that accepts immutable inputs and produces immutable outputs or side-effects (queries, DDL).

There are no "entities" in the DDD sense because SQLCraft is not the system of record. A `TableMeta` snapshot does not "change"; you re-fetch to get a fresh snapshot.

**Consequence:** PHP `readonly` classes and `readonly` constructor-promoted properties are used throughout. `clone with` (PHP 8.4) is the only mutation path — it returns a new instance.

---

## 2. Domain Glossary

| Term | Definition |
|------|-----------|
| **Identifier** | A single unquoted database object name (table, column, etc.). Carries quoting responsibility via the platform. |
| **QualifiedName** | An ordered tuple (catalog?, schema?, object) forming a fully-qualified reference. |
| **Platform** | An engine-specific adapter that knows SQL dialect, quoting rules, type system, and capabilities. |
| **Driver** | A connection-factory and platform selector. Creates `Connection` instances for a given DSN. |
| **Connection** | A live, single-database PDO wrapper. Executes statements; never leaks PDO. |
| **Capability** | A named feature flag for a platform, optionally version-gated. |
| **CapabilitySet** | An immutable set of Capability values applicable to a specific platform+version combination. |
| **ColumnMeta** | Immutable read-model of one column as returned by introspection. Replaces Adminer's `Field` array. |
| **TableStatus** | Immutable read-model of table-level metadata. Replaces Adminer's `TableStatus` array. |
| **MetadataFactory** | A per-platform hydrator that converts raw PDO rows into typed VOs/DTOs. |
| **DDL Generator** | A platform-aware service that emits SQL DDL strings from VO inputs. |
| **Flavor** | A sub-variant of a driver family (e.g., `MariaDB` is a flavor of the MySQL driver). |
| **SchemaInspector** | Application service that orchestrates introspection calls and returns typed DTOs. |
| **ExecutionResult** | Immutable result of a query: row data, affected-row count, last-insert-id. |

---

## 3. Value Objects

Value objects are **immutable, equality-by-value** objects. In PHP 8.4 they are `readonly` classes. They never hold database connections, never issue queries, never emit output. Their only behaviour is validation (in the constructor) and value derivation (pure methods).

### 3.1 `Identifier`

Wraps a single unquoted object name. Rejects empty strings and null bytes. Quoting is deferred to the platform — the `Identifier` itself does not know the quoting character.

```php
namespace SQLCraft\ValueObjects;

final readonly class Identifier
{
    public function __construct(public readonly string $name)
    {
        if ($name === '' || str_contains($name, "\0")) {
            throw new \InvalidArgumentException("Invalid identifier: '{$name}'");
        }
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name;
    }

    public function __toString(): string { return $this->name; }
}
```

**Adminer equivalent:** raw strings passed to `idf_escape()`. The VO makes the unquoted/quoted distinction explicit.

### 3.2 `QualifiedName`

A tuple of (catalog?, schema?, object). The platform decides how to render it.

```php
final readonly class QualifiedName
{
    public function __construct(
        public readonly Identifier       $object,
        public readonly ?Identifier      $schema  = null,
        public readonly ?Identifier      $catalog = null,
    ) {}

    /** Render with platform-aware quoting — called by DDL/query builders */
    public function qualify(int $depth = 3): self { return $this; } // clone with depth
}
```

### 3.3 `DataType`

```php
final readonly class DataType
{
    public function __construct(
        public readonly string   $name,           // 'VARCHAR', 'INT', 'JSONB', etc.
        public readonly ?int     $length       = null,
        public readonly ?int     $precision    = null,
        public readonly ?int     $scale        = null,
        public readonly bool     $unsigned     = false,
        public readonly ?string  $collation    = null,
        public readonly ?string  $charset      = null,
    ) {}
}
```

**Adminer equivalent:** The `type`, `length`, `unsigned`, `collation` fields from `FieldType`. Now a single typed VO.

### 3.4 Additional Value Objects (no PHP sketch — see domain model detail)

| VO | Adminer equivalent | Notes |
|----|-------------------|-------|
| `DefaultValue` | `Field.default` string | Distinguishes `NULL`, `''`, literal, expression, sequence |
| `ForeignKeyAction` | `on_delete`/`on_update` strings | `enum` with cases RESTRICT, CASCADE, SET_NULL, NO_ACTION, SET_DEFAULT |
| `TriggerTiming` | `Timing` string | `enum`: BEFORE, AFTER, INSTEAD_OF |
| `TriggerEvent` | `Event` string | `enum`: INSERT, UPDATE, DELETE, TRUNCATE |
| `RoutineDirection` | `inout` string | `enum`: IN, OUT, INOUT |
| `IndexType` | `type` string in Index | `enum`: PRIMARY, UNIQUE, INDEX, FULLTEXT, SPATIAL |
| `Charset` | scattered strings | Wraps charset name, validates against known set |
| `Collation` | `Field.collation` string | Wraps collation string |
| `Engine` | `TableStatus.Engine` string | MySQL/MariaDB storage engine (InnoDB, MyISAM, etc.) |
| `ServerVersion` | `connection->server_info` string | Parses semver; used for capability resolution |

---

## 4. DTOs / Metadata Read-Models

DTOs are `readonly` classes hydrated from driver query results. Unlike VOs, DTOs may carry nullable fields (because DB metadata introspection surfaces nullable columns for some engines).

### 4.1 `ColumnMeta` — full sketch (replaces Adminer's `Field` typeAlias)

```php
namespace SQLCraft\DTO;

use SQLCraft\ValueObjects\{DataType, DefaultValue, Collation};

final readonly class ColumnMeta
{
    public function __construct(
        public readonly string       $name,
        public readonly DataType     $dataType,
        public readonly bool         $nullable,
        public readonly bool         $autoIncrement,
        public readonly bool         $primary,
        public readonly bool         $generated,
        public readonly DefaultValue $default,
        public readonly ?Collation   $collation,
        public readonly ?string      $comment,
        public readonly ?string      $onUpdate,   // MySQL ON UPDATE CURRENT_TIMESTAMP
        public readonly array        $privileges, // bitmask: SELECT=1, INSERT=2, UPDATE=4, etc.
        public readonly ?string      $origName,   // original name before rename (for ALTER diffs)
        public readonly ?string      $defaultConstraintName, // MSSQL named default constraint
    ) {}
}
```

**Adminer `Field` mapping:**
- `field` → `name`
- `full_type` + `type` + `length` + `unsigned` + `collation` → `DataType $dataType`
- `null` → `nullable`
- `auto_increment` → `autoIncrement`
- `default` → `DefaultValue $default` (discriminated, not raw string)
- `privileges: int[]` preserved
- `generated` → `generated`
- `on_update` → `onUpdate`
- `orig` → `origName`
- `default_constraint` → `defaultConstraintName`

### 4.2 `TableStatus` — full sketch (replaces Adminer's `TableStatus` typeAlias)

```php
final readonly class TableStatus
{
    public function __construct(
        public readonly string  $name,
        public readonly bool    $isView        = false,
        public readonly ?string $engine        = null,  // InnoDB, MyISAM, etc.
        public readonly ?string $comment       = null,
        public readonly ?int    $oid           = null,  // PgSQL object OID
        public readonly ?int    $rows          = null,  // approximate; NULL if unknown
        public readonly ?string $collation     = null,
        public readonly ?int    $autoIncrement = null,
        public readonly ?int    $dataLength    = null,
        public readonly ?int    $indexLength   = null,
        public readonly ?int    $dataFree      = null,
        public readonly ?string $createOptions = null,
        public readonly bool    $partitioned   = false,
        public readonly ?string $schema        = null,  // nspname for PgSQL
    ) {}
}
```

### 4.3 `ForeignKeyMeta` — full sketch

```php
final readonly class ForeignKeyMeta
{
    /**
     * @param list<string> $sourceColumns
     * @param list<string> $targetColumns
     */
    public function __construct(
        public readonly string          $constraintName,
        public readonly ?string         $targetDatabase,
        public readonly ?string         $targetSchema,
        public readonly string          $targetTable,
        public readonly array           $sourceColumns,
        public readonly array           $targetColumns,
        public readonly ForeignKeyAction $onDelete,
        public readonly ForeignKeyAction $onUpdate,
        public readonly ?string         $definition,  // raw for engines that don't decompose
        public readonly bool            $deferrable   = false,
    ) {}
}
```

---

## 5. Entities vs Value Objects

Almost nothing in SQLCraft is an "entity" in the DDD sense. An entity has a stable identity that survives mutation. SQLCraft produces **read-models**, not mutable domain entities, because:

1. **The DB is the aggregate root.** A `TableStatus` snapshot does not "change" — the table does. You re-fetch to get a fresh snapshot.
2. **Immutability prevents stale-read bugs.** A mutable `TableMeta` could be modified by a caller after fetch, creating a divergence between the object and the DB. Readonly classes make this impossible.
3. **Immutable objects are thread/coroutine-safe.** In Swoole/ReactPHP/Amp environments, shared readonly objects need no locking.
4. **Easier reasoning in application code.** A `ColumnMeta` passed into a DDL diff is guaranteed to reflect what was fetched; no defensive copying needed.

The only quasi-entity is `Connection` — it wraps a live PDO resource and has identity (two `Connection` objects with the same DSN are still different resources). But `Connection` is infrastructure, not domain.

**`clone with` for "mutation":** When a caller needs to derive a modified VO (e.g., to model a proposed schema change), PHP 8.4's `clone with` creates a new instance:

```php
$modified = $column with { nullable: false, comment: 'required field' };
// $column unchanged; $modified is a new ColumnMeta
```

---

## 6. Collections

Typed collections wrap arrays of homogeneous VOs/DTOs. See the Collections module (doc 07) for full detail. From the domain model perspective:

- `ColumnCollection` is returned by `MetadataService::getColumns()` — ordered, keyed by name.
- `IndexCollection` is returned by `MetadataService::getIndexes()`.
- `TableCollection` is returned by `MetadataService::getTables()`.
- Collections are immutable — `filter()`, `map()`, `sort()` return new instances.
- PHPStan/Psalm `@template` generics provide static type checking without runtime overhead.

---

## 7. Services (stateless, injected)

Services hold no mutable state. They receive their dependencies (connections, platforms, resolvers) via constructor injection, operate on immutable inputs, and return immutable outputs (or emit side-effects like DDL execution).

| Service | Interface | Responsibility |
|---------|-----------|---------------|
| `MetadataService` | `MetadataServiceInterface` | Introspect DB: tables, columns, indexes, FKs, triggers, routines, sequences |
| `SchemaInspector` | `SchemaInspectorInterface` | Higher-level: compare two schemas, produce diff |
| `DdlBuilder` | `DdlBuilderInterface` | Convert VOs to DDL SQL strings |
| `QueryBuilder` | `QueryBuilderInterface` | Fluent SELECT/INSERT/UPDATE/DELETE construction |
| `Executor` | `ExecutorInterface` | Execute statements, return `ExecutionResult` |
| `Importer` | `ImporterInterface` | Stream SQL/CSV/other into a DB |
| `Exporter` | `ExporterInterface` | Stream DB content out as SQL/CSV/JSON |
| `SecurityGuard` | `SecurityGuardInterface` | Evaluate privileges for a user/object pair |

Services are defined as interfaces in `Contracts`; implementations live in their respective bounded contexts.

---

## 8. MetadataFactory / Hydrators

Raw PDO rows are typed-array structures from the DB. They must be converted into typed DTOs. This conversion is the `MetadataFactory`'s responsibility.

```php
namespace SQLCraft\Metadata;

/** @internal — not part of public API; used only by MetadataService implementations */
interface MetadataFactoryInterface
{
    public function createColumnMeta(array $row): ColumnMeta;
    public function createTableStatus(array $row): TableStatus;
    public function createIndexMeta(array $row): IndexMeta;
    public function createForeignKeyMeta(array $row): ForeignKeyMeta;
    public function createTriggerMeta(array $row): TriggerMeta;
    public function createRoutineMeta(array $row): RoutineMeta;
}
```

Each platform ships its own factory (e.g., `MySQLMetadataFactory`, `PostgreSQLMetadataFactory`) because column type strings, index type tokens, and FK action labels differ per engine. The factory is internal to the Metadata module; application services never call it directly.

**Hydration responsibility split:**
- The factory maps raw row arrays to typed DTOs.
- The platform's `IntrospectionDialectInterface` provides the SQL that generates those rows.
- The `MetadataService` drives the fetch-and-hydrate loop.

This isolates: (1) SQL dialect in the platform, (2) row-to-DTO mapping in the factory, (3) orchestration in the service.

---

## 9. Events and Exceptions

### Events (named, not detailed here — see doc 07 Events module)

Core events emitted by SQLCraft services:
- `QueryExecutedEvent` — after any statement execution
- `SlowQueryEvent` — if execution exceeds a configurable threshold
- `DdlExecutedEvent` — after any DDL statement
- `ConnectionOpenedEvent` / `ConnectionClosedEvent`
- `CapabilityNotSupportedEvent` — when a capability check fails (before exception)

All events are `final readonly` classes with relevant payload (SQL, duration, connection info).

### Exception Hierarchy

```
SQLCraftException (base)
├── ConnectionException
│   ├── ConnectionFailedException
│   ├── AuthenticationException
│   └── ConnectionLostException
├── QueryException
│   ├── SyntaxErrorException       // carries SQL + error message
│   ├── ConstraintViolationException
│   │   ├── UniqueConstraintException
│   │   └── ForeignKeyConstraintException
│   └── DeadlockException
├── MetadataException
│   └── ObjectNotFoundException    // table/column/index does not exist
├── CapabilityException
│   └── CapabilityNotSupportedException  // carries Capability + platform + version
├── SecurityException
│   └── InsufficientPrivilegesException
├── DriverException
│   ├── DriverNotFoundException
│   └── DriverMisconfiguredException
└── ImportExportException
    ├── ImportFailedException
    └── ExportFailedException
```

All exceptions are `final` classes extending the hierarchy. They carry typed properties (not just a message string) so callers can inspect the cause programmatically.

---

## 10. Identity and Equality Model

**Value Objects:** Equality by value. Two `DataType` instances with identical fields are equal. PHP has no built-in structural equality for objects, so VOs that need comparison implement an `equals(self $other): bool` method comparing all properties. PHPStan enforces no accidental `==` on objects via a custom rule.

**DTOs:** No identity concept — they are snapshots. Two `ColumnMeta` snapshots fetched at different times with the same data are "equal" but represent potentially different DB states.

**Collections:** Two `ColumnCollection` instances with the same ordered items are functionally equivalent; no `equals()` is needed since they are rebuilt from DB each fetch.

**Identifiers:** `Identifier` equality is **case-sensitive by default** (PHP string comparison). Case-insensitive comparison (for MySQL and SQLite identifiers) is a platform concern, provided via `PlatformInterface::normalizeIdentifier()`. The domain model does not bake in case folding — doing so would be wrong for PostgreSQL (case-sensitive by default when quoted).

**No `==` on objects rule:** All VO equality checks use named `equals()` methods or PHPStan-enforced strict identity (`===`). This prevents silent PHP object comparison bugs where two different instances with identical values compare as `false` under `===` but `true` under `==` for objects with `__equals` magic (which SQLCraft does not use).

