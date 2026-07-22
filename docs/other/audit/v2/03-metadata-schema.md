# Audit 03 — Metadata (Introspection) & Schema

**Date:** 2026-07-21
**Scope:** `src/Metadata/`, `src/Schema/`, `src/Contracts/Metadata/`, `tests/Unit/Metadata`, `tests/Unit/Schema`, `tests/Integration/Schema`, `tests/Golden`
**Mode:** Read-only. No source files were modified.

---

## 1. Inspector Inventory

### 1.1 Findings

Plans doc `docs/plans/11-schema-services.md` §3 promises thirteen named inspectors. Twelve are present; one is a dead contract.

| # | Inspector | Implementation file | SchemaManager injection | Status |
|---|---|---|---|---|
| 1 | ServerInspector | `src/Metadata/ServerInspector.php` | `$this->serverInspector` | OK |
| 2 | DatabaseInspector | `src/Metadata/DatabaseInspector.php` | `$this->databaseInspector` | OK |
| 3 | TableInspector | `src/Metadata/TableInspector.php` | `$this->tableInspector` | OK |
| 4 | ColumnInspector | `src/Metadata/ColumnInspector.php` | `$this->columnInspector` | OK |
| 5 | IndexInspector | `src/Metadata/IndexInspector.php` | `$this->indexInspector` | OK |
| 6 | ForeignKeyInspector | `src/Metadata/ForeignKeyInspector.php` | `$this->foreignKeyInspector` | OK |
| 7 | ViewInspector | `src/Metadata/ViewInspector.php` | `$this->viewInspector` | OK |
| 8 | RoutineInspector | `src/Metadata/RoutineInspector.php` | `$this->routineInspector` | OK |
| 9 | TriggerInspector | `src/Metadata/TriggerInspector.php` | `$this->triggerInspector` | OK |
| 10 | SequenceInspector | `src/Metadata/SequenceInspector.php` | `$this->sequenceInspector` | OK |
| 11 | CheckConstraintInspector | `src/Metadata/CheckConstraintInspector.php` | `$this->checkConstraintInspector` | OK |
| 12 | UserInspector | `src/Metadata/UserInspector.php` | `$this->userInspector` | OK |
| 13 | **PrivilegeInspector** | **missing** | **not wired** | **GAP** |

### 1.2 Gap — PrivilegeInspector

- **Severity:** Medium
- **Promise:** `docs/plans/11-schema-services.md` §3.12 lists `PrivilegeInspectorInterface` alongside `UserInspectorInterface`.
- **Reality:** `src/Contracts/Metadata/PrivilegeInspectorInterface.php` exists (the contract), but there is no `PrivilegeInspector.php` anywhere in `src/`. The interface is not injected into `SchemaManager`. Zero callers.
- **Fix:** Create `src/Metadata/PrivilegeInspector.php` implementing `PrivilegeInspectorInterface`, add it to `SchemaManager`'s constructor, and wire it in `SchemaManagerFactory`.

---

## 2. Per-Platform MetadataFactory

### 2.1 Findings

`src/Schema/SchemaManagerFactory::metadataFactory()` resolves the factory via a `match` block:

```php
return match ($connection->getPlatformName()) {
    'mysql', 'mariadb' => new MySQLMetadataFactory(),
    'pgsql'            => new PostgreSQLMetadataFactory(),
    'sqlite'           => new SqliteMetadataFactory(),
    default => throw new InvalidArgumentException(...),
};
```

| Platform | Factory file | Status |
|---|---|---|
| MySQL | `src/Metadata/MySQLMetadataFactory.php` | PRESENT |
| MariaDB | reuses `MySQLMetadataFactory` (see match above) | PRESENT (reuse) |
| PostgreSQL | `src/Metadata/PostgreSQLMetadataFactory.php` | PRESENT |
| SQLite | `src/Metadata/SqliteMetadataFactory.php` | PRESENT |
| **SQL Server** | **missing** | **GAP — runtime crash** |

MariaDB reuse is intentional and matches plan intent; no separate `MariaDbMetadataFactory` is needed.

### 2.2 Gap — SqlServerMetadataFactory

- **Severity:** Critical
- **Promise:** `docs/plans/07-module-breakdown.md` §8 names SQL Server as a first-class target platform. M8 milestone shipped `SqlServerPlatform`, `SqlServerDriver`, and `SqlServerIntegrationTest`.
- **Reality:** `SqlServerMetadataFactory` does not exist. The `default` branch throws `InvalidArgumentException`, so any call to `SchemaManager` on a SQL Server connection fails immediately at factory-selection time. SQL Server schema introspection is completely non-functional despite the driver layer being present.
- **Fix:** Create `src/Metadata/SqlServerMetadataFactory.php` extending `AbstractMetadataFactory` and add `'sqlserver' => new SqlServerMetadataFactory()` to the match block.

---

## 3. allFields() / Cross-Table Column Batching

### 3.1 Finding — Compliant (single batched query)

The plan (`docs/plans/04-feature-inventory.md` §19, `docs/plans/11-schema-services.md` §3.4) requires cross-table column enumeration via a single batched query, not N+1 per-table calls.

The implementation in `src/Metadata/ColumnInspector.php`:

```php
public function getAllColumns(
    ConnectionInterface $conn,
    string $database,
    ?string $schema = null
): array {
    $rows = $conn->query(
        $conn->getPlatform()->getAllColumnsSql($database, $schema)
    )->fetchAll();

    $columns = [];
    foreach ($rows as $row) {
        $tableName = $this->tableName($row);
        $column    = $this->factory->createColumnMeta($row);
        $columns[$tableName] ??= [];
        $columns[$tableName][$column->name] = $column;
    }

    return array_map(
        static fn (array $tableColumns): ColumnCollection => new ColumnCollection($tableColumns),
        $columns,
    );
}
```

One call to `getAllColumnsSql()`; grouping by table is done in PHP. `SchemaManager::getAllColumns()` additionally wraps this in `$this->cached(...)` with a composite key. No N+1 detected.

The MySQL golden fixture confirms the generated SQL:
```sql
SELECT * FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'public'
ORDER BY TABLE_NAME, ORDINAL_POSITION
```

- **Status:** Compliant. No gap.

---

## 4. MetadataCacheInterface Seam and Invalidation

### 4.1 Cache interface and implementations

`src/Contracts/Metadata/MetadataCacheInterface.php` defines:

```php
interface MetadataCacheInterface
{
    public function remember(string $key, callable $loader, int $ttl = 0): mixed;
    public function invalidateTable(string $database, string $table): void;
    public function invalidateDatabase(string $database): void;
    public function clear(): void;
}
```

`docs/plans/11-schema-services.md` §5 lists four planned implementations. Only one exists:

| Implementation | File | Status |
|---|---|---|
| `NullMetadataCache` | `src/Schema/NullMetadataCache.php` | PRESENT — `remember()` calls loader; others are no-ops |
| `InMemoryMetadataCache` | missing | **GAP** |
| `Psr6MetadataCache` | missing | **GAP** |
| `Psr16MetadataCache` | missing | **GAP** |

- **Severity:** Medium (NullMetadataCache is acceptable for correctness; missing impls block production cache adoption)
- **Fix:** Implement the three missing cache adapters as described in `docs/plans/11-schema-services.md` §5.

### 4.2 Gap — Cache invalidation seam wired but never closed

- **Severity:** High
- **Promise:** `docs/plans/11-schema-services.md` and the M4 roadmap state that after any DDL operation, `SchemaChangedEvent` causes the cache to be invalidated for the affected table or database.
- **Reality:**
  - `SchemaChangedEvent` (`src/Events/SchemaChangedEvent.php`) and `AfterDdlExecuted` (`src/Events/AfterDdlExecuted.php`) are dispatched by `SchemaEventDispatcher` and `QueryExecutor`.
  - `SchemaManager` holds `?SchemaEventDispatcherInterface $events` but uses it only for an observability call (`metadataFetched`).
  - No listener, subscriber, or handler anywhere in `src/` calls `$cache->invalidateTable()`, `$cache->invalidateDatabase()`, or `$cache->clear()` in response to those events.
  - The entire invalidation pathway is a dead circuit: events fire, no handler receives them, the cache is never cleared after DDL.
- **Fix:** Add a `CacheInvalidationListener` (or equivalent) that receives `SchemaChangedEvent`/`AfterDdlExecuted` and calls the appropriate `MetadataCacheInterface` invalidation method. Wire the listener into the event dispatcher during `SchemaManager` construction.

---

## 5. Capability-Gated Inspectors

### 5.1 Gap — No inspector performs a capability check

- **Severity:** High
- **Promise:** `docs/plans/11-schema-services.md` §7 and `docs/plans/09-capability-model.md` require inspectors to call `$caps->require(Capability::X)` before issuing a query on an unsupported platform, throwing `CapabilityNotSupportedException`.
- **Reality:** Grep across all files in `src/Metadata/` returns zero matches for `require(`, `CapabilityNotSupportedException`, or any `Capability::` reference. Every inspector delegates blindly to the platform dialect:

```php
// src/Metadata/SequenceInspector.php — representative example
public function getSequences(ConnectionInterface $conn, ?string $schema = null): SequenceCollection
{
    $rows = $conn->query($conn->getPlatform()->getSequencesSql($schema))->fetchAll();
    // hydrates rows, returns collection — no capability check
}
```

The same pattern holds for `CheckConstraintInspector`, `TriggerInspector`, `RoutineInspector`, and `UserInspector`.

- **What actually happens:** If the platform dialect method is internally guarded (some are), a `CapabilityNotSupportedException` propagates from inside the platform, not the inspector. If the dialect returns a query string without guarding, the inspector silently returns an empty collection or triggers a database error. Behavior is inconsistent and depends on dialect internals rather than explicit inspector contracts.
- **Note:** The capability infrastructure exists — `src/Capabilities/CapabilitySet.php`, `src/Capabilities/Capability.php`, and `src/Exceptions/CapabilityNotSupportedException.php` are all present. The gap is adoption inside the Metadata layer.
- **Fix:** In each inspector, resolve the `CapabilitySet` from the connection and call `$caps->require(Capability::X)` before issuing the dialect query. The exact capability enum value per inspector is specified in `docs/plans/09-capability-model.md`.

---

## 6. Golden-File Introspection SQL Snapshots

### 6.1 Findings

`tests/Golden/fixtures/` contains:

| File | Platform | SQL snapshots covered |
|---|---|---|
| `mysql-introspection.sql` | MySQL | 17 methods |
| `mariadb-introspection.sql` | MariaDB | 17 methods |
| `pgsql-introspection.sql` | PostgreSQL | 17 methods |
| `sqlite-introspection.sql` | SQLite | 17 methods |
| `sqlserver-introspection.sql` | **SQL Server** | **MISSING** |

`tests/Golden/IntrospectionSqlGoldenTest.php` has no SQL Server entry in `platformProvider()`. Each fixture records all 17 introspection SQL methods, rendering unsupported ones as `"UNSUPPORTED: ..."` strings.

### 6.2 Gap — SQL Server golden file missing

- **Severity:** Medium (test coverage gap; also reflects missing SqlServerMetadataFactory above)
- **Promise:** Golden tests cover all target platforms per `docs/plans/20-testing.md`.
- **Reality:** SQL Server has no golden fixture and no test entry. `tests/Integration/SqlServer/SqlServerIntegrationTest.php` exists but only exercises connection/query behaviour, not introspection SQL generation.
- **Fix:** After adding `SqlServerMetadataFactory` (gap 2.2), generate the golden fixture by running `php artisan kiro:golden --platform=sqlserver` (or equivalent) and add a `yield 'sqlserver'` entry to `platformProvider()`.

---

## 7. Dead / Orphan Seams

### 7.1 PrivilegeInspectorInterface — contract with no implementation

- **Severity:** Medium
- **File:** `src/Contracts/Metadata/PrivilegeInspectorInterface.php`
- **Reality:** Defines `getPrivileges()` returning `PrivilegeCollection`. No concrete class implements it. Not injected anywhere. See gap 1.2.

### 7.2 DatabaseInspector::getSequences() — implemented but unreachable

- **Severity:** Low
- **File:** `src/Metadata/DatabaseInspector.php` lines 40-52
- **Reality:** `DatabaseInspectorInterface` includes `getSequences()`, and `DatabaseInspector` fully implements it. However, `SchemaManager::getSequences()` routes to `$this->sequenceInspector`, not `$this->databaseInspector`. `DatabaseInspector::getSequences()` is therefore unreachable from the public API. Either the method should be removed from `DatabaseInspectorInterface` (if it belongs on `SequenceInspector` alone) or `SchemaManager` should delegate to it explicitly.
- **Fix:** Remove `getSequences()` from `DatabaseInspectorInterface` and `DatabaseInspector`, or document the intentional dual path.

### 7.3 Three planned cache implementations absent

- **Severity:** Medium
- **Reality:** `InMemoryMetadataCache`, `Psr6MetadataCache`, `Psr16MetadataCache` are named in plan docs but have no files in `src/`. See gap 4.1.

### 7.4 SqlServerMetadataFactory — platform-level dead seam

- **Severity:** Critical
- **Reality:** `SqlServerPlatform` and `SqlServerDriver` are live. The metadata factory that would make them useful for schema introspection does not exist. See gap 2.2.

---

## 8. Summary Table

| # | Area | Severity | Plan reference | Status |
|---|---|---|---|---|
| 1 | PrivilegeInspector missing (contract only) | Medium | 11-schema-services §3.12 | GAP |
| 2 | SqlServerMetadataFactory missing — runtime crash | **Critical** | 07-module-breakdown §8 | GAP |
| 3 | allFields() / getAllColumns() — single batched query | — | 04-feature-inventory §19 | COMPLIANT |
| 4 | Cache impls: only NullMetadataCache present (3 missing) | Medium | 11-schema-services §5 | GAP |
| 5 | Cache invalidation: events dispatched, no listener wires to cache | **High** | 11-schema-services §6, M4 | GAP |
| 6 | Capability gating absent from all inspectors | **High** | 09-capability-model, 11-schema-services §7 | GAP |
| 7 | SQL Server golden test fixture missing | Medium | 20-testing | GAP |
| 8 | DatabaseInspector::getSequences() — dead, unreachable | Low | — | DEAD CODE |
| 9 | MariaDB reuses MySQLMetadataFactory | — | 07-module-breakdown | COMPLIANT |
