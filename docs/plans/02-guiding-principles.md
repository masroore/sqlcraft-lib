# SQLCraft Planning — 02: Guiding Principles

> Status: **Planning phase. No code exists yet.**
> Last updated: 2026-07-20

---

## Introduction

Each principle below is stated as a concrete decision, not an abstract aspiration. For each one: the rationale, the Adminer anti-pattern it corrects, and the tradeoff honestly acknowledged. These principles are binding — a pull request that violates one requires explicit justification and project-lead approval.

---

## 1. Single Responsibility (SRP)

**Decision:** Each class does exactly one thing. A `MetadataService` reads schema information; it never writes DDL. A `ColumnDefinition` VO carries column metadata; it never generates SQL. A `MySQLPlatform` knows MySQL dialect; it never knows about HTTP requests.

**Adminer anti-pattern corrected:** Adminer's `Adminer` class (`adminer/include/adminer.inc.php`, 1100+ lines) mixes credentials logic, navigation HTML generation, table-name formatting, dump hooks, login form rendering, and CSRF token handling. The `SqlDriver` subclasses mix schema introspection (free functions `fields()`, `tables_list()`), SQL generation (`idf_escape()`, `limit()`), transaction management, and capability detection — all in one class.

**Tradeoff:** More classes, more files. The project feels verbose in scaffolding. The payoff is that each unit is independently testable, and changing the MySQL dialect's `limit()` clause does not risk breaking the privilege management logic.

---

## 2. Open/Closed (OCP)

**Decision:** Core services are open for extension through interfaces and events, closed for modification. Adding a new export format requires implementing `ExportFormatterInterface` and registering it — no changes to `ExportService`. Adding a new driver requires implementing `DriverInterface`/`PlatformInterface` — no changes to any existing driver.

**Adminer anti-pattern corrected:** Adminer's plugin system routes extension through `__call` on a `Plugins` class that dispatches to `Plugin` subclasses by method name matching. Adding a new capability means either forking the core or hoping a hook exists. The driver list is hard-coded in `bootstrap.inc.php` via sequential `include` calls.

**Tradeoff:** Requires discipline in interface design. Interfaces must be correct up-front; changing them later is a BC break. This is why the planning phase is thorough — getting the contracts right before any code.

---

## 3. Liskov Substitution (LSP)

**Decision:** Every implementation of `DriverInterface::listTables()` returns a `TableCollection` with the same contract. If `PostgreSQLDriver` cannot list tables without a schema qualifier, it throws `MissingContextException` — it does not return an empty collection silently. Subtypes never weaken postconditions or strengthen preconditions.

**Adminer anti-pattern corrected:** Adminer's free function `tables_list()` is defined once per driver file as a plain function in the same namespace. The return type is `array`, and different drivers return subtly different shapes. The MySQL driver returns `[$name => $type]`; the PostgreSQL driver includes `nspname`. Callers must know which engine they are on to interpret results.

**Tradeoff:** Enforcing uniform return shapes sometimes requires normalisation work inside a driver implementation. For example, PostgreSQL's `pg_catalog.pg_tables` returns schema + table as separate columns; the driver normalises these into a `QualifiedName` VO before returning.

---

## 4. Interface Segregation (ISP)

**Decision:** Interfaces are narrow and role-based. A consumer that only reads metadata receives `MetadataServiceInterface`, not a god object that also exposes `truncateTable()` and `dropDatabase()`. The driver is split into `ConnectionInterface` (connect/quote/execute), `PlatformInterface` (dialect/capability/SQL generation), and `IntrospectionInterface` (schema reading). No interface has more than ~10 methods.

**Adminer anti-pattern corrected:** Adminer's `SqlDriver` is a single abstract class with 30+ methods covering everything from `engines()` to `checkConstraints()` to `quoteBinary()` to `slowQuery()`. There is no way to pass "read-only DB access" as a typed contract.

**Tradeoff:** More interfaces to navigate when reading the code. Mitigated by a clear naming convention (`*Interface` suffix, grouped by bounded context in the `Contracts\` namespace) and thorough doc-comments.

---

## 5. Dependency Inversion (DIP)

**Decision:** High-level services (`MetadataService`, `DDLService`) depend on abstractions (`ConnectionInterface`, `PlatformInterface`), not on concrete PDO wrappers or engine classes. All dependencies are injected via constructor. No service instantiates its own dependencies.

**Adminer anti-pattern corrected:** Adminer uses three global singleton accessors — `connection()`, `driver()`, `adminer()` — which return static class instances. Any code anywhere in the codebase calls `connection()->query(...)` without declaring that dependency. This makes unit testing impossible without bootstrapping the entire application.

**Tradeoff:** Every service requires wiring at construction time. Mitigated by a `SQLCraftFactory` helper and optional PSR-11 container integration that handles wiring automatically.

---

## 6. Composition Over Inheritance

**Decision:** Shared behaviour is delivered via interfaces + traits, not deep inheritance chains. Platform-specific SQL generation is not a long `MySQLDriver extends AbstractDriver extends SqlDriver` chain — it is a `MySQLPlatform` that `implements PlatformInterface` and uses `IdentifierQuoterTrait`, `LimitClauseTrait`, etc., for the parts that are shared.

**Adminer anti-pattern corrected:** Adminer has `PdoDb extends SqlDb` for the PDO base, then MySQL extends `SqlDb` directly (using the MySQL extension), while PostgreSQL, SQLite, MSSQL, and Oracle all extend `PdoDb`. This creates different inheritance depths for different drivers and makes it impossible to compose behaviours across the hierarchy.

**Tradeoff:** Traits require discipline — they must not carry state, must be documented, and must not create invisible coupling. The rule: a trait may provide utility methods but may not hold instance properties.

---

## 7. Immutability and Value Objects

**Decision:** All data returned by SQLCraft is immutable. Metadata VOs are `readonly` classes. Command objects (requests to change schema) are built with a fluent builder that returns a new instance on each mutation. No VO has a setter.

**Rationale:** Immutable objects are safe to cache, safe to pass into event listeners, safe to return from generators without defensive copies. Mutability introduces the class of bugs where a caller modifies a returned object and expects the modification to propagate — or worse, does not expect it and accidentally mutates shared state.

**Adminer anti-pattern corrected:** Adminer uses loose `array` shapes (`Field`, `TableStatus`, `Index`, `ForeignKey`) everywhere. A `Field` array carries `['field' => ..., 'type' => ..., 'null' => ...]`. Nothing prevents a caller from silently adding a key, getting `null` for a missing key, or mistyping a key name.

**PHP 8.4 implementation:**

```php
readonly class ColumnDefinition
{
    public function __construct(
        public readonly string          $name,
        public readonly ColumnType      $type,
        public readonly ?int            $length,
        public readonly bool            $nullable,
        public readonly bool            $autoIncrement,
        public readonly bool            $primaryKey,
        public readonly bool            $unsigned,
        public readonly ?string         $default,
        public readonly ?string         $comment,
        public readonly ?string         $collation,
        public readonly GeneratedType   $generated,
        public readonly Privileges      $privileges,
    ) {}
}
```

**Tradeoff:** Constructing VOs requires all fields at once — there is no incremental build. For construction-heavy code (e.g., parsing a `SHOW COLUMNS` result row), a dedicated `ColumnDefinitionFactory` or named-argument construction smooths this.

---

## 8. Fail-Fast Typed Exceptions

**Decision:** Every error condition throws a typed exception from SQLCraft's exception hierarchy. There are no silent `null` returns for "entity not found," no suppressed errors, no `@`-prefixed calls. Callers must handle errors explicitly.

```php
// Never:
function listColumns(string $table): ?ColumnCollection  // null on error

// Always:
function listColumns(string $table): ColumnCollection   // throws on error
// throws TableNotFoundException if $table does not exist
// throws ConnectionException if the DB connection is lost
```

**Adminer anti-pattern corrected:**
- Adminer's `PdoDb::dsn()` returns a string error message on failure instead of throwing. Callers must `if (!is_object($return))` check.
- `@ini_set()`, `@$this->pdo->getAttribute()` — dozens of `@` error suppressors throughout.
- `idx($array, $key)` is used as a safe null-coalesce because array keys may or may not exist in a given driver's result.
- `connection()->query($sql)` returns `Result|bool` — a boolean `false` on failure, with the error stored in `$conn->error`.

**Exception hierarchy:**

```
SQLCraftException (base)
├── ConnectionException
│   ├── ConnectionFailedException
│   └── ConnectionLostException
├── QueryException
│   ├── QuerySyntaxException
│   └── QueryTimeoutException
├── SchemaException
│   ├── TableNotFoundException
│   ├── ColumnNotFoundException
│   └── DatabaseNotFoundException
├── CapabilityException
│   └── FeatureNotSupportedException
├── ImportException
│   ├── ParseException
│   └── ImportChunkException
└── SecurityException
    └── PrivilegeException
```

**Tradeoff:** Callers must wrap operations in try/catch where they previously could rely on falsy returns. This is intentional — it makes error-handling visible instead of invisible.

---

## 9. Capability-Driven Design — Never Fake, Never Lowest-Common-Denominator

> **Source of truth:** `09-capability-model.md` defines the complete `Capability` enum and platform matrix. This section describes the design principle only; its abbreviated historical example is not normative.

**Decision:** The `Capability` enum contains one entry per named feature. Each `Platform` implementation declares a `CapabilityMap` at construction time. Any code that uses a capability calls `$platform->requires(Capability::X)` (throws if absent) or `$platform->supports(Capability::X)` (returns bool) before proceeding.

```php
enum Capability: string
{
    case Schemas            = 'schemas';           // PG/MSSQL namespaces
    case MaterializedViews  = 'materialized_view';
    case DeferrableForeignKeys = 'deferrable_fk';
    case StoredProcedures   = 'procedures';
    case Events             = 'events';            // MySQL/MariaDB scheduler
    case Sequences          = 'sequences';         // PG, Oracle, MariaDB 10.3+
    case PartialIndexes     = 'partial_indexes';   // PG, SQLite
    case DescendingIndexes  = 'desc_indexes';
    case CheckConstraints   = 'check_constraints';
    case ColumnComments     = 'column_comments';
    case TableEngines       = 'table_engines';     // MySQL/MariaDB InnoDB/MyISAM
    case Collations         = 'collations';
    case KillProcess        = 'kill_process';
    case QueryTimeout       = 'query_timeout';
    case FullTextSearch     = 'fulltext';
    // ... complete list in 08-capability-model.md
}
```

**Adminer anti-pattern corrected:** Adminer uses `support(string $feature): bool` where `$feature` is an unchecked string literal matched by `preg_match`. Call sites look like `support("scheme")`, `support("trigger")`, `support("partial_indexes")`. Typos in string literals are silent `false` at runtime.

**Tradeoff:** The `Capability` enum must be kept up-to-date. Adding a capability to the enum without updating all platform capability maps triggers `\ValueError` or `\UnhandledMatchError` depending on the implementation style — intentionally fail-fast.

---

## 10. PDO is the Only Low-Level Abstraction — Hidden Behind Interfaces

**Decision:** SQLCraft requires PDO and uses it exclusively. No direct MySQL extension (`mysqli`), no PostgreSQL extension (`pgsql`), no SQLite extension (`sqlite3`). However, PDO is never exposed in the public API. The `ConnectionInterface` contract has `execute()`, `query()`, `quote()`, `beginTransaction()`, etc. — not `\PDO`. Tests can inject a `FakePdoConnection` that implements `ConnectionInterface` without touching real PDO.

**Rationale:** PDO is the only cross-engine, built-in, universally available PHP DB abstraction. It is safe to depend on. But leaking `\PDO` in the public API would prevent test doubles, prevent alternative transport implementations, and tie the API to PDO's sometimes-inconsistent interface.

**Adminer anti-pattern corrected:** Adminer's MySQL driver historically used the `mysqli` extension directly (`mysqli_query`, `mysqli_fetch_assoc`) and only switched to PDO via `PdoDb` for cross-driver compatibility. The PDO `\PDOStatement` is exposed directly as the result type.

**Tradeoff:** The PDO wrapping layer adds a small indirection overhead (one method call). The performance cost is unmeasurable relative to network I/O in any real database operation.

---

## 11. PHP 8.4 Idioms — Used Where They Add Clarity

**Readonly classes:** All VOs and DTOs are `readonly` classes. No setters, no `clone with` workarounds needed.

**Constructor promotion:** Used in all VOs and most services. Reduces boilerplate while keeping types explicit.

**Enums:** Used for `Capability`, `ColumnType`, `IndexType`, `ForeignKeyAction`, `RoutineLanguage`, `ExportFormat`, `ImportFormat`. Never plain string constants.

**Typed constants:** `public const string VERSION = '1.0.0'`. No untyped `const`.

**`declare(strict_types=1)`:** Every file. No implicit coercions.

**`match` expressions:** Preferred over `switch` for exhaustiveness checking.

**First-class callables:** `array_map(fn($col) => $col->name, $cols->toArray())` — no string method references.

**Property hooks (PHP 8.4):** Considered but used sparingly, only where they genuinely replace a common getter pattern without hiding behaviour. Not used in VOs (readonly covers the need). May appear in builder classes for derived-property computation.

**What is explicitly rejected:**
- `mixed` type — every parameter and return type is explicit.
- Dynamic properties — `declare(strict_types=1)` + `#[\AllowDynamicProperties]` is never used.
- Magic `__get`/`__set`/`__call` — no invisible dispatch.
- Nullable `?Type` as a lazy alternative to a proper union — use `Type|null` only when null genuinely means "absent".
- Service locators — no `Container::getInstance()`, no `App::make()`.

---

## 12. Coding Standards

| Standard | Requirement |
|---|---|
| PSR-1 | Basic coding standards (class names, method names) |
| PSR-4 | Autoloading: `SQLCraft\` → `src/` |
| PSR-12 | Extended coding style (braces, spacing, visibility) |
| PHPStan | Level max (level 9), strict rules enabled |
| Psalm | Level 1 (strictest), `errorLevel="1"` |
| Rector | Config provided; Rector-compatible patterns enforced |
| PHPUnit | 10.x; no `@covers` shortcuts; descriptive test names |

PHPStan and Psalm must both pass with zero issues at every commit (enforced in CI). Rector is run as an upgrade-compatibility check, not as an auto-fixer in CI. All new code is reviewed against the principles in this document before merge.
