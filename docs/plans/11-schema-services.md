# 11 — Schema Services

> **Status:** Design draft
> **Scope:** `SQLCraft\Metadata` and `SQLCraft\Schema` namespaces — stateless introspection services, per-object-type inspector interfaces, `information_schema` vs native-catalog strategy, lazy loading, caching seam, capability gating, `SchemaManager` facade, search-across-tables service
> **Depends on:** 05-domain-model.md (ColumnMeta, TableStatus, ForeignKeyMeta, DTOs), 08-driver-architecture.md (PlatformInterface, IntrospectionDialectInterface), 09-capability-model.md (Capability enum, CapabilitySet), 10-connection-layer.md (ConnectionInterface)
> **Namespace root:** `SQLCraft\Metadata`, `SQLCraft\Schema`

---

## 1. Design Philosophy

Database administration begins with discovery — knowing what objects exist, their structure, and their relationships. The schema services layer is SQLCraft's answer to Adminer's free-function introspection scattered across `adminer.php` (e.g., `tables_list()`, `fields()`, `indexes()`, `foreign_keys()`). SQLCraft replaces that with:

- **Stateless service objects** keyed by connection + platform — no object holds DB state.
- **Typed DTOs** (from 05-domain-model.md) as return values — no raw associative arrays.
- **Capability-gated operations** (from 09-capability-model.md) — services refuse to pretend support for features the engine lacks.
- **Platform-delegated SQL** (from 08-driver-architecture.md) — service code never contains engine-specific SQL strings.
- **Lazy loading boundaries** — you pay for what you fetch; column lists are not loaded when you only asked for table names.

---

## 2. Information Schema vs Native Catalog Strategy

The two broad families of metadata retrieval SQL are:

| Approach | Advantages | Disadvantages | Primary users |
|----------|-----------|---------------|---------------|
| `INFORMATION_SCHEMA` | SQL standard (ISO/IEC 9075); portable queries; available on MySQL/MariaDB/PgSQL/SQLite/MSSQL | Notoriously slow on large MySQL DBs (full table-lock scan on metadata tables); PostgreSQL's `information_schema` is accurate but verbose; not all engines implement all views | MySQL, MariaDB, PostgreSQL, SQLite, MSSQL |
| Native system catalogs | Fast (uses internal index structures); full feature coverage (e.g., PgSQL `pg_class`, `pg_attribute`, `pg_constraint`) | Engine-specific; no cross-engine portability; may change between versions | PostgreSQL (`pg_catalog`), Oracle (`ALL_*`/`USER_*`), MSSQL (`sys.*`) |

**Decision — native catalogs preferred, `INFORMATION_SCHEMA` as fallback:**

Each platform's `IntrospectionDialectInterface` implementation (see 08-driver-architecture.md §10) supplies the queries. The rule is:

1. Use the native catalog if the engine provides one that is fast and complete.
2. Fall back to `INFORMATION_SCHEMA` for portability and for engines where native catalogs are not more capable.
3. Never mix both in the same query — the hybrid approach creates maintenance complexity without gain.

Concretely:
- **MySQL/MariaDB:** `INFORMATION_SCHEMA` is adequate for most operations; `SHOW TABLE STATUS` for row/size estimates (faster than `information_schema.TABLES`).
- **PostgreSQL:** `pg_catalog` (native) for columns, constraints, indexes; `information_schema` only where `pg_catalog` is genuinely more complex for no gain.
- **SQLite:** `PRAGMA table_info()`, `PRAGMA foreign_key_list()`, `PRAGMA index_list()` — SQLite has no `INFORMATION_SCHEMA`; PRAGMAs are the only API.
- **MSSQL:** `sys.columns`, `sys.tables`, `sys.indexes`, `sys.foreign_keys` — `INFORMATION_SCHEMA` is available but `sys.*` is richer.
- **Oracle:** `ALL_TAB_COLUMNS`, `ALL_CONSTRAINTS`, `ALL_INDEXES` — Oracle's `INFORMATION_SCHEMA` is a thin wrapper; native views are preferred.

---

## 3. Inspector Service Interfaces

Each inspector is a stateless service. It receives a `ConnectionInterface` and returns immutable DTOs or typed collections. Services hold no query state. The `PlatformInterface` (and specifically `IntrospectionDialectInterface`) is obtained from `$connection->getPlatform()` — services never need an explicit platform parameter.

### 3.1 `ServerInspectorInterface`

```php
namespace SQLCraft\Contracts\Metadata;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\DTO\{ServerInfo, ProcessInfo, Charset, Collation};
use SQLCraft\Collections\{DatabaseCollection, CharsetCollection, CollationCollection, ProcessCollection};

interface ServerInspectorInterface
{
    public function getServerInfo(ConnectionInterface $conn): ServerInfo;

    /** @return DatabaseCollection list of database/catalog names this connection can access */
    public function getDatabases(ConnectionInterface $conn): DatabaseCollection;

    /** SHOW VARIABLES / pg_settings equivalent; capability-gated (Capability::Variables) */
    public function getVariables(ConnectionInterface $conn): array; // map<string, string>

    /** SHOW STATUS / pg_stat equivalent; capability-gated (Capability::Status) */
    public function getStatus(ConnectionInterface $conn): array;

    /** Running processes; capability-gated (Capability::Processlist) */
    public function getProcessList(ConnectionInterface $conn): ProcessCollection;

    /** Available character sets; capability-gated (Capability::Charset) */
    public function getCharsets(ConnectionInterface $conn): CharsetCollection;

    /** Available collations; capability-gated (Capability::Collation) */
    public function getCollations(ConnectionInterface $conn, ?string $charset = null): CollationCollection;
}
```

```php
final readonly class ServerInfo
{
    public function __construct(
        public readonly ServerVersion $version,
        public readonly string        $platformName,
        public readonly ?string       $flavor,
        public readonly ?string       $dataDirectory,
        public readonly ?string       $timezone,
        public readonly ?string       $charset,
        public readonly ?string       $collation,
    ) {}
}
```

### 3.2 `DatabaseInspectorInterface` / `SchemaInspectorInterface`

```php
interface DatabaseInspectorInterface
{
    /** List all schemas/namespaces within the current database (PgSQL, MSSQL, Oracle) */
    public function getSchemas(ConnectionInterface $conn): SchemaCollection;

    /** List sequences at the database or schema level; capability-gated (Capability::Sequence) */
    public function getSequences(ConnectionInterface $conn, ?string $schema = null): SequenceCollection;

    /** List custom types (PgSQL enum/composite/domain); capability-gated (Capability::Type) */
    public function getTypes(ConnectionInterface $conn, ?string $schema = null): TypeCollection;
}
```

Schemas in SQLite (via ATTACH) and MySQL (which conflates database/schema) are surfaced as empty unless the platform declares `Capability::Scheme`.

### 3.3 `TableInspectorInterface`

```php
interface TableInspectorInterface
{
    /**
     * List all tables (and views, partitioned tables) with their TableStatus snapshot.
     * Never fetches column details — that is ColumnInspector's job.
     *
     * @return TableCollection keyed by table name
     */
    public function getTables(ConnectionInterface $conn, ?string $schema = null): TableCollection;

    /** Single table status by qualified name */
    public function getTableStatus(ConnectionInterface $conn, QualifiedName $table): TableStatus;

    /**
     * Table inheritance (PgSQL INHERITS); returns empty collection on engines that lack it.
     * @return QualifiedNameCollection parent table names
     */
    public function getParentTables(ConnectionInterface $conn, QualifiedName $table): QualifiedNameCollection;

    /** Partition metadata; capability-gated (Capability::Partitions) */
    public function getPartitions(ConnectionInterface $conn, QualifiedName $table): PartitionCollection;
}
```

`TableStatus` (from 05-domain-model.md §4.2) carries row/size estimates. Row counts from `SHOW TABLE STATUS` on MySQL are approximate for InnoDB; the `rows` field is nullable to reflect this explicitly.

### 3.4 `ColumnInspectorInterface`

```php
interface ColumnInspectorInterface
{
    /**
     * All columns of a table, ordered by ordinal position.
     * @return ColumnCollection keyed by column name
     */
    public function getColumns(ConnectionInterface $conn, QualifiedName $table): ColumnCollection;

    /** Single column by name */
    public function getColumn(ConnectionInterface $conn, QualifiedName $table, Identifier $column): ColumnMeta;
}
```

`ColumnMeta` (05-domain-model.md §4.1) is the richest DTO. The platform's `IntrospectionDialectInterface::getColumnsSql()` generates the query; the per-platform `MetadataFactory` (05-domain-model.md §8) hydrates the rows into typed `ColumnMeta` objects. No service touches raw row data.

### 3.5 `IndexInspectorInterface`

```php
interface IndexInspectorInterface
{
    /** @return IndexCollection including PRIMARY, UNIQUE, INDEX, FULLTEXT, SPATIAL */
    public function getIndexes(ConnectionInterface $conn, QualifiedName $table): IndexCollection;
}
```

```php
final readonly class IndexMeta
{
    /** @param list<IndexColumnMeta> $columns */
    public function __construct(
        public readonly string     $name,
        public readonly IndexType  $type,        // PRIMARY | UNIQUE | INDEX | FULLTEXT | SPATIAL
        public readonly array      $columns,
        public readonly bool       $unique,
        public readonly ?string    $comment,
        public readonly ?string    $algorithm,   // BTREE, HASH, GiST, GIN, etc.
        public readonly ?string    $filterExpression, // partial index (PgSQL/SQLite)
    ) {}
}

final readonly class IndexColumnMeta
{
    public function __construct(
        public readonly string  $columnName,
        public readonly bool    $descending,
        public readonly ?int    $length,          // prefix length (MySQL)
        public readonly ?string $expression,      // functional index expression
    ) {}
}
```

### 3.6 `ForeignKeyInspectorInterface`

```php
interface ForeignKeyInspectorInterface
{
    /**
     * Foreign keys WHERE the given table is the source (child side).
     * @return ForeignKeyCollection
     */
    public function getForeignKeys(ConnectionInterface $conn, QualifiedName $table): ForeignKeyCollection;

    /**
     * "Backward keys" — foreign keys pointing TO this table (parent side).
     * Adminer calls these BackwardKey and uses them for navigation links.
     * @return ForeignKeyCollection
     */
    public function getReferencingKeys(ConnectionInterface $conn, QualifiedName $table): ForeignKeyCollection;
}
```

The "backward key" concept from Adminer is preserved explicitly. Adminer computes these by scanning all FK definitions in the database looking for references to the current table. SQLCraft delegates this to the platform's introspection dialect, which may use `INFORMATION_SCHEMA.KEY_COLUMN_USAGE` (MySQL) or `pg_constraint` (PgSQL).

`ForeignKeyMeta` is defined in 05-domain-model.md §4.3.

### 3.7 `ViewInspectorInterface`

```php
interface ViewInspectorInterface
{
    /** All views (non-materialized); capability-gated (Capability::View) */
    public function getViews(ConnectionInterface $conn, ?string $schema = null): ViewCollection;

    /** View definition SQL */
    public function getViewDefinition(ConnectionInterface $conn, QualifiedName $view): string;

    /** Materialized views; capability-gated (Capability::MaterializedView) */
    public function getMaterializedViews(ConnectionInterface $conn, ?string $schema = null): ViewCollection;
}
```

### 3.8 `RoutineInspectorInterface`

```php
interface RoutineInspectorInterface
{
    /** Stored functions; capability-gated (Capability::Routine) */
    public function getFunctions(ConnectionInterface $conn, ?string $schema = null): RoutineCollection;

    /** Stored procedures; capability-gated (Capability::Procedure) */
    public function getProcedures(ConnectionInterface $conn, ?string $schema = null): RoutineCollection;

    /** Full routine detail including parameter list and body */
    public function getRoutineDetail(ConnectionInterface $conn, QualifiedName $routine): RoutineMeta;
}
```

```php
final readonly class RoutineMeta
{
    /** @param list<RoutineParamMeta> $params */
    public function __construct(
        public readonly string  $name,
        public readonly string  $type,         // 'FUNCTION' | 'PROCEDURE'
        public readonly array   $params,
        public readonly ?DataType $returnType, // null for procedures
        public readonly string  $body,
        public readonly ?string $language,     // 'SQL', 'PLPGSQL', etc.
        public readonly ?string $comment,
        public readonly string  $definer,
        public readonly bool    $deterministic,
        public readonly string  $sqlDataAccess, // READS SQL DATA, MODIFIES SQL DATA, etc.
    ) {}
}

final readonly class RoutineParamMeta
{
    public function __construct(
        public readonly string          $name,
        public readonly DataType        $dataType,
        public readonly RoutineDirection $direction, // IN | OUT | INOUT
    ) {}
}
```

### 3.9 `TriggerInspectorInterface`

```php
interface TriggerInspectorInterface
{
    /** capability-gated (Capability::Trigger) */
    public function getTriggers(ConnectionInterface $conn, QualifiedName $table): TriggerCollection;
}
```

```php
final readonly class TriggerMeta
{
    public function __construct(
        public readonly string        $name,
        public readonly TriggerTiming $timing,    // BEFORE | AFTER | INSTEAD_OF
        public readonly TriggerEvent  $event,     // INSERT | UPDATE | DELETE | TRUNCATE
        public readonly string        $body,
        public readonly ?string       $definer,
        public readonly ?string       $table,     // referenced table for INSTEAD OF
    ) {}
}
```

### 3.10 `SequenceInspectorInterface`

```php
interface SequenceInspectorInterface
{
    /** capability-gated (Capability::Sequence) */
    public function getSequences(ConnectionInterface $conn, ?string $schema = null): SequenceCollection;
}
```

```php
final readonly class SequenceMeta
{
    public function __construct(
        public readonly string   $name,
        public readonly ?string  $schema,
        public readonly int|string $startValue,
        public readonly int|string $minValue,
        public readonly int|string $maxValue,
        public readonly int      $increment,
        public readonly bool     $cycle,
        public readonly ?string  $ownedByTable,  // PgSQL OWNED BY
        public readonly ?string  $ownedByColumn,
    ) {}
}
```

### 3.11 `CheckConstraintInspectorInterface`

```php
interface CheckConstraintInspectorInterface
{
    /** capability-gated (Capability::CheckConstraints) — see 09-capability-model.md matrix */
    public function getCheckConstraints(ConnectionInterface $conn, QualifiedName $table): CheckConstraintCollection;
}
```

```php
final readonly class CheckConstraintMeta
{
    public function __construct(
        public readonly string  $name,
        public readonly string  $expression, // raw SQL CHECK expression
        public readonly bool    $enforced,   // MySQL 8.0.16+ has ENFORCED flag
    ) {}
}
```

### 3.12 `UserInspectorInterface` / `PrivilegeInspectorInterface`

```php
interface UserInspectorInterface
{
    /** capability-gated (Capability::Privileges) */
    public function getUsers(ConnectionInterface $conn): UserCollection;
}

interface PrivilegeInspectorInterface
{
    /** Privileges for a given user on a given object (table-level, column-level, DB-level) */
    public function getPrivileges(ConnectionInterface $conn, ?string $user = null, ?QualifiedName $object = null): PrivilegeCollection;
}
```

These are capability-gated: `Capability::Privileges` is only present on MySQL and MariaDB (see 09-capability-model.md §6 matrix). PostgreSQL surfaces privilege info differently via `pg_roles`/`has_table_privilege()`; the inspector uses whatever the platform's dialect provides.

---

## 4. Lazy Loading and Laziness Boundaries

Inspector calls are cheap in isolation. The expensive part is fetching data not needed. The laziness contract is:

- `getTables()` returns `TableStatus` objects **without** column data.
- `getColumns()` is a separate, explicit call.
- `getIndexes()`, `getForeignKeys()`, `getTriggers()` are each separate calls.
- No inspector pre-fetches related objects unless the method name explicitly implies it (e.g., a hypothetical `getTableFull()` would be a deliberate all-in-one).

**Generators for large table lists:**

On databases with hundreds or thousands of tables, `getTables()` may be slow. The `TableInspectorInterface` can be called with a streaming variant:

```php
interface TableInspectorInterface
{
    // Standard (buffered — returns full collection)
    public function getTables(ConnectionInterface $conn, ?string $schema = null): TableCollection;

    // Streaming (generator — constant memory for large databases)
    /** @return \Generator<string, TableStatus> */
    public function streamTables(ConnectionInterface $conn, ?string $schema = null): \Generator;
}
```

`streamTables()` is backed by a streaming query (`$streaming = true` on `ConnectionInterface::query()`). The generator yields `(name => TableStatus)` pairs without materializing the full collection. This is the appropriate default for export and schema-dump operations.

---

## 5. Caching Seam

Schema metadata is expensive to fetch and rarely changes within a request. SQLCraft provides a caching interface but ships no concrete cache implementation — consumers inject their preferred PSR-6 or PSR-16 cache.

```php
namespace SQLCraft\Contracts\Metadata;

interface MetadataCacheInterface
{
    /**
     * Fetch a cached result by key, or call $loader and cache the result.
     * TTL of 0 = session-scoped (valid until clear() called).
     *
     * @template T
     * @param callable(): T $loader
     * @return T
     */
    public function remember(string $key, callable $loader, int $ttl = 0): mixed;

    /** Invalidate all metadata for a specific table */
    public function invalidateTable(string $database, string $table): void;

    /** Invalidate all metadata for a database */
    public function invalidateDatabase(string $database): void;

    /** Invalidate everything */
    public function clear(): void;
}
```

The `SchemaManager` facade (§6) accepts an optional `MetadataCacheInterface`. When injected, each inspector call is wrapped in `$cache->remember(...)` using a key derived from `{platform}/{database}/{schema}/{object}/{method}`.

**Invalidation contract:** After any DDL operation (via SchemaChanged event, see 16-events.md), the cache is invalidated for the affected table or database. This prevents stale reads after `ALTER TABLE` or `CREATE INDEX`.

**Built-in implementations:**

| Class | Strategy |
|-------|----------|
| `NullMetadataCache` | No caching; default; every call hits the DB |
| `InMemoryMetadataCache` | PHP array; request-scoped; suitable for FPM |
| `Psr6MetadataCache` | Wraps any PSR-6 `CacheItemPoolInterface` |
| `Psr16MetadataCache` | Wraps any PSR-16 `CacheInterface` |

---

## 6. `SchemaManager` Facade

Application code should not need to inject six separate inspector services. The `SchemaManager` aggregates all inspectors and provides a single injection point:

```php
namespace SQLCraft\Schema;

final class SchemaManager
{
    public function __construct(
        private readonly ServerInspectorInterface          $serverInspector,
        private readonly TableInspectorInterface           $tableInspector,
        private readonly ColumnInspectorInterface          $columnInspector,
        private readonly IndexInspectorInterface           $indexInspector,
        private readonly ForeignKeyInspectorInterface      $fkInspector,
        private readonly ViewInspectorInterface            $viewInspector,
        private readonly RoutineInspectorInterface         $routineInspector,
        private readonly TriggerInspectorInterface         $triggerInspector,
        private readonly SequenceInspectorInterface        $sequenceInspector,
        private readonly CheckConstraintInspectorInterface $checkInspector,
        private readonly UserInspectorInterface            $userInspector,
        private readonly ?MetadataCacheInterface           $cache = null,
    ) {}

    // Convenience delegates:
    public function getTables(ConnectionInterface $conn, ?string $schema = null): TableCollection
    {
        return $this->tableInspector->getTables($conn, $schema);
    }

    public function getColumns(ConnectionInterface $conn, QualifiedName $table): ColumnCollection
    {
        return $this->columnInspector->getColumns($conn, $table);
    }

    // ... etc. for every inspector method
}
```

**Factory:** A `SchemaManagerFactory` builds the correctly wired `SchemaManager` for a given connection by reading the platform and instantiating the platform-specific inspector implementations. Application code should use the factory rather than assembling the manager by hand.

```php
$manager = SchemaManagerFactory::forConnection($conn, cache: new InMemoryMetadataCache());
$tables  = $manager->getTables($conn);
$columns = $manager->getColumns($conn, new QualifiedName(new Identifier('users')));
```

---

## 7. Capability Gating in Services

Every method that touches a capability-gated feature uses the `$caps->require(...)` guard pattern (09-capability-model.md §7):

```php
// Inside TriggerInspectorImpl
public function getTriggers(ConnectionInterface $conn, QualifiedName $table): TriggerCollection
{
    $conn->getPlatform()
         ->getCapabilitySet($conn->getServerVersion())
         ->require(Capability::Trigger);

    $sql  = $conn->getPlatform()->getTriggersSql($table);
    $rows = $conn->query($sql)->fetchAll();
    return $this->factory->createTriggerCollection($rows);
}
```

The exception `CapabilityNotSupportedException` (09-capability-model.md §10) carries the capability name and platform, so callers can render a meaningful message ("SQLite does not support triggers") rather than catching a generic error.

---

## 8. Search Across Tables

Adminer has a "search" feature that runs a WHERE clause across every column of every table matching a search term. SQLCraft models this as a dedicated service:

```php
namespace SQLCraft\Contracts\Schema;

interface TableSearchServiceInterface
{
    /**
     * Search for a value across all columns of all matching tables.
     *
     * @param string[]|null  $tables  Null = all tables
     * @param string[]|null  $columns Null = all columns
     * @return \Generator<SearchResult>  — streaming; yields one SearchResult per matching row
     */
    public function search(
        ConnectionInterface $conn,
        string              $searchTerm,
        ?array              $tables  = null,
        ?array              $columns = null,
        int                 $rowCap  = 1000, // safety cap per table
    ): \Generator;
}
```

```php
final readonly class SearchResult
{
    public function __construct(
        public readonly string $tableName,
        public readonly string $columnName,
        public readonly mixed  $value,
        public readonly array  $row,   // full row context
    ) {}
}
```

The implementation iterates tables, fetches column lists, builds a `WHERE (col1 LIKE ? OR col2 LIKE ? ...)` query per table using bound parameters, and yields results as a generator. The `$rowCap` prevents accidental full-table scans from exhausting memory. Large-table approximate counts (via `TableStatus::$rows`) inform whether a table is safe to search without the cap.

**Design decision — generator vs buffered collection:** Generator is mandatory here. Cross-table search can produce millions of rows; returning a collection would exhaust memory on any non-trivial database.

---

## 9. Contrast with Adminer's Introspection

Adminer's introspection is composed of free functions (`tables_list()`, `fields()`, `indexes()`, `foreign_keys()`, `get_rows()`) that:
- Live in engine-specific PHP files (adminer/drivers/mysql.inc.php, etc.).
- Return raw associative arrays with undocumented shapes.
- Have no capability gating — they silently fail or return empty arrays.
- Cannot be mocked or substituted in tests without replacing the function.

SQLCraft's approach:
- **Services are injectable.** A test can inject a mock `TableInspectorInterface` that returns fixture data.
- **DTOs have documented shapes.** `ColumnMeta` has typed constructor parameters and PHPStan/Psalm annotations.
- **Capability gating is explicit.** Calling `getTriggers()` on SQLite throws `CapabilityNotSupportedException`; Adminer returns an empty array silently.
- **Platform SQL is isolated.** Changing a MySQL introspection query does not affect PgSQL. In Adminer, a cross-engine `if/elseif` chain handles this in the same function body.

---

## 10. Summary of Key Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Catalog strategy | Native catalog preferred, INFORMATION_SCHEMA fallback | Speed + accuracy for each engine; portability where native is not needed |
| Service granularity | One interface per object type | SRP; callers depend only on what they use; mocking is per-concern |
| Return types | Typed DTOs / typed collections | No raw arrays; PHPStan/Psalm can verify callers |
| Lazy loading | Explicit separate calls per object type | Avoids N+1 preloading; callers pay for exactly what they need |
| Streaming | `streamTables()` generator variant | Constant memory for large databases |
| Caching | Interface seam; NullMetadataCache default | No forced cache dependency; consumer chooses PSR-6/PSR-16 |
| Capability gating | `require()` throws, never silent empty return | Explicit; debuggable; meaningful error messages |
| Facade | `SchemaManager` aggregates all inspectors | Single injection point; platform-specific wiring via factory |
| Search | Generator-based `TableSearchService` with row cap | Memory safety; streaming; no arbitrary full-table scan |
