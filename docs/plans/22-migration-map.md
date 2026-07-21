# SQLCraft Planning — 22: Migration Map

> Status: **Planning phase. No code exists yet.**
> Last updated: 2026-07-20
> Purpose: a complete lookup table from every significant Adminer subsystem and free function to its SQLCraft equivalent, so a developer who knows Adminer's source can find the SQLCraft parallel immediately. This document is a *reference*, not a tutorial — read `03-adminer-analysis.md` first for the narrative reverse-engineering, and `05-domain-model.md`/`06-package-architecture.md`/`08-driver-architecture.md`/`09-capability-model.md`/`16-events.md` for the target-side rationale.

---

## Master Mapping Table

| Adminer component/function | Adminer file | SQLCraft equivalent | SQLCraft namespace | Architectural improvement |
|---|---|---|---|---|
| `SqlDb` (abstract connection base) | `include/db.inc.php:6-56` | `ConnectionInterface` + `PdoConnection` | `SQLCraft\Contracts\Connection`, `SQLCraft\Connection` | Interface-first; PDO never surfaces past the adapter; no `static $instance` |
| `SqlDb::attach()` | `include/db.inc.php` | `DriverInterface::connect()` | `SQLCraft\Contracts\Driver` | Throws typed `ConnectionFailedException` instead of returning an error string the caller must `is_object()`-check |
| `PdoDb extends SqlDb` | `include/pdo.inc.php:6-68` | `PdoConnection` | `SQLCraft\Connection` | One concrete PDO wrapper for all engines, not a mysqli-shaped compatibility shim (`fetch_field()` type-code mapping) |
| `PdoResult extends \PDOStatement` | `include/pdo.inc.php:70-100` | `ResultInterface` (streaming/buffered) | `SQLCraft\Contracts\Connection` | Explicit streaming vs buffered contract; no mysqli-legacy `fetch_assoc()`/`fetch_row()` shim methods |
| `SqlDriver` (capability flags + behavior) | `include/driver.inc.php:14-296` | `PlatformInterface` (segregated) + `AbstractPlatform` | `SQLCraft\Contracts\Platform`, `SQLCraft\Platform` | God class split into `QuotingInterface`/`PaginationInterface`/`TypeMapperInterface`/`DdlDialectInterface`/`IntrospectionDialectInterface` |
| `SqlDriver::$insertFunctions`, `$editFunctions`, `$operators`, `$functions`, `$grouping` (public array props) | `include/driver.inc.php:14-18` | `TypeMapperInterface::getSupportedTypes()`, `PlatformInterface::getOperators()`, aggregate-function allowlist | `SQLCraft\Contracts\Platform` | Typed methods, not public mutable arrays; validated via `OperatorValidator` at VO construction (15-security.md §5) |
| `Adminer` class (customization host) | `include/adminer.inc.php` (1100+ lines) | Split: `CredentialProvider`, custom `InspectorInterface` impls, `FormatWriterInterface`, `ImportSourceInterface`, DI-injected services | `SQLCraft\Contracts\*` (per concern) | Logic hooks and UI hooks are no longer methods on one class; see §9 below for the full hook-by-hook mapping |
| `Plugins` (magic dispatch aggregator) | `include/plugins.inc.php:4-92` | PSR-14 `EventDispatcherInterface` + explicit extension interfaces | `SQLCraft\Events`, `SQLCraft\Contracts\*` | No `__call`; no hardcoded short-circuit/append exemption list; every hook is a typed, IDE-discoverable event class |
| `support(string $feature): bool` | `drivers/mysql.inc.php:1064`, etc. | `Capability` enum + `CapabilitySet::has()`/`require()` | `SQLCraft\Capabilities` | Typo-safe enum case vs unchecked string; `CapabilityNotSupportedException` carries structured context |
| Version `preg_match` gating (e.g. CHECK constraint detection) | scattered in `adminer.php` | `PlatformCapabilityResolver` + `ServerVersion::isAtLeast()` | `SQLCraft\Platform`, `SQLCraft\ValueObjects` | Single source of truth per platform; testable offline without a live connection |
| `DB`, `SERVER`, `JUSH`, `ME`, `DRIVER` constants | `bootstrap.inc.php:43,87-98`; `mysql.inc.php:7` | `ConnectionInterface` instance, `PlatformInterface::getName()`, contextual VOs passed as arguments | (n/a — no global constants) | No `define()`; every consumer gets its own connection/platform reference, no process-wide singleton |
| `$_SESSION[$key][$driver][$server][$username]` (saved passwords, history, bookmarks) | `auth.inc.php`, `editing.inc.php` | `QueryHistoryInterface` (consumer-injected storage); credentials never stored by SQLCraft | `SQLCraft\Contracts\Execution` | Storage-agnostic interface; SQLCraft holds no session/cookie state at all |
| `connection()`, `driver()`, `adminer()` singleton accessor functions | `functions.inc.php:6-29` | Constructor-injected `ConnectionInterface`/`PlatformInterface`; `DatabaseSession` aggregate | `SQLCraft` (root) | No global accessor functions; dependency graph is visible in every constructor signature |
| `tables_list()` | `drivers/mysql.inc.php:439` | `SchemaManager::listTables()` → `TableCollection` | `SQLCraft\Metadata` | Typed collection, not `array<string, string>` |
| `table_status($name, $fast)` | `drivers/mysql.inc.php:459` | `SchemaManager::describeTable()` (status portion) → `TableStatus` DTO | `SQLCraft\DTO` | `readonly` DTO with nullable fields per-engine, not a loose array with engine-dependent key presence |
| `fields($table)` | `drivers/mysql.inc.php:501` | `SchemaManager::describeTable()->columns` → `ColumnCollection<ColumnMeta>` | `SQLCraft\DTO`, `SQLCraft\Collections` | `DataType`/`DefaultValue` sub-VOs replace stringly-typed `full_type`/`default` fields |
| `indexes($table, $connection2)` | `drivers/mysql.inc.php:554` | `SchemaManager::describeTable()->indexes` → `IndexCollection<IndexMeta>` | `SQLCraft\DTO`, `SQLCraft\Collections` | `IndexType` enum replaces string `type` field |
| `foreign_keys($table)` | `drivers/mysql.inc.php:570` | `SchemaManager::describeTable()->foreignKeys` → `ForeignKeyCollection<ForeignKeyMeta>` | `SQLCraft\DTO`, `SQLCraft\Collections` | `ForeignKeyAction` enum replaces `on_delete`/`on_update` strings |
| `triggers($table)` | `drivers/mysql.inc.php:868` | `SchemaManager::describeTable()->triggers` → `TriggerCollection<TriggerMeta>` | `SQLCraft\DTO`, `SQLCraft\Collections` | `TriggerTiming`/`TriggerEvent` enums replace string `Timing`/`Event` |
| `routines()` | `drivers/mysql.inc.php:921` | `SchemaManager::listRoutines()` → `RoutineCollection<RoutineMeta>` | `SQLCraft\DTO`, `SQLCraft\Collections` | `RoutineDirection` enum replaces `inout` string |
| `schemas()` | `drivers/mysql.inc.php:1111` | `SchemaManager::listSchemas()` → `SchemaMeta` collection | `SQLCraft\DTO` | Absent-capability engines (MySQL/SQLite) simply don't implement the method meaningfully rather than returning an empty array with no explanation |
| `idf_escape($idf)` | per-driver (`mysql.inc.php:376`, `pgsql.inc.php:390`, ...) | `QuotingInterface::quoteIdentifier(Identifier)` | `SQLCraft\Contracts\Platform` | Takes a validated `Identifier` VO, not a raw string |
| `table($idf)` | per-driver | `IdentifierQuoter::quoteQualified(QualifiedName)` | `SQLCraft\Security` | Schema-qualification is structural (`QualifiedName` VO), not string concatenation |
| `limit($query, $where, $limit, $offset, $sep)` | per-driver | `PaginationInterface::applyPagination()` | `SQLCraft\Contracts\Platform` | One method contract instead of a free function redefined per included driver file |
| `limit1($table, $query, $where, $sep)` | per-driver | `PaginationInterface::applySingleRowLimit()` | `SQLCraft\Contracts\Platform` | Same contract point for every engine; PgSQL's ctid-subquery strategy is an implementation detail behind the interface |
| `convert_field()` / `unconvert_field()` | per-driver | `QuotingInterface::convertFieldIn()`/`convertFieldOut()` | `SQLCraft\Contracts\Platform` | Symmetric named methods vs two loosely related free functions |
| `quoteBinary()` | per-driver | `QuotingInterface::quoteBinary()` | `SQLCraft\Contracts\Platform` | Same method name/signature across all five v1 platforms |
| `create_sql()` | `adminer.php` (compiled) | `CreateTableBuilder` + `DdlDialectInterface::renderCreateTable()` | `SQLCraft\DDL`, `SQLCraft\Contracts\Platform` | Intent (builder VO) separated from rendering (platform); previewable via `toSql()` before `execute()` |
| `alter_sql()` | `adminer.php` | `AlterTableBuilder` + `DdlDialectInterface::renderAlterTable()` | `SQLCraft\DDL` | Returns `list<string>`, making multi-statement ALTER (PgSQL, SQLite recreation) explicit rather than implicit |
| `truncate_sql()` | `adminer.php` | `TruncateBuilder` + `DdlDialectInterface::renderTruncate()` | `SQLCraft\DDL` | — |
| `trigger_sql()` | `adminer.php` | `CreateTriggerBuilder` + `DdlDialectInterface::renderCreateTrigger()` | `SQLCraft\DDL` | DELIMITER wrapping is a platform concern, not string surgery at the call site |
| `recreate_table()` (SQLite ALTER workaround) | `drivers/sqlite.inc.php` | `SqlitePlatform`'s `AlterTableBuilder` rendering → `TableRecreationStrategy` | `SQLCraft\DDL` | Named, testable strategy object instead of an inline procedural function |
| `SqlDriver::select()` | `include/driver.inc.php:86-104` | `SelectQuery` VO + `SelectQueryRenderer` + `QueryExecutor::query()` | `SQLCraft\Query`, `SQLCraft\Execution` | Immutable VO with allowlisted operators; renderer separated from execution |
| `SqlDriver::insert()` / `update()` / `delete()` / `insertUpdate()` | `include/driver.inc.php` | `QueryExecutor::execute()` with bound parameters | `SQLCraft\Execution` | No default implementations relying on an implicit cross-file function contract (`limit()`/`table()`) |
| `SqlDriver::begin()` / `commit()` / `rollback()` | `include/driver.inc.php` | `TransactionManagerInterface::begin()`/`Transaction::commit()`/`rollback()` | `SQLCraft\Contracts\Execution` | Savepoint-based nesting instead of ad-hoc engine-specific handling |
| `SqlDriver::slowQuery()` | `include/driver.inc.php` | `QueryExecutor::queryWithTimeout()` + `SlowQueryDetectedEvent` | `SQLCraft\Execution`, `SQLCraft\Events` | Observable via PSR-14 event, not a bespoke method contract every caller must know to invoke |
| `SqlDriver::warnings()` | `include/driver.inc.php` | `WarningsProviderInterface::getWarnings()` | `SQLCraft\Contracts\Execution` | Returns `WarningCollection` of typed `QueryWarning`, not raw `SHOW WARNINGS` rows |
| `SqlDriver::checkConstraints()` (with inline `flavor == 'maria'` branch) | `include/driver.inc.php:270-275` | `CheckConstraintInspector` implementation per platform | `SQLCraft\Metadata` | Flavor branching lives once in `MariaDbPlatform`, not inline in a shared method every caller passes through |
| `dump.inc.php` (streaming echo export) | `adminer/dump.inc.php` | `Exporter` + format `FormatWriterInterface` (SQL/CSV/TSV/JSON/XML) + injectable sinks | `SQLCraft\Export` | Never writes to `php://output`/echoes; writes to any injected stream, testable without HTTP |
| SQL/CSV import parsing (inline in `dump.inc.php`/`import` handling) | `adminer/dump.inc.php` | `StatementSplitterInterface` + `BatchExecutor` + `Importer` | `SQLCraft\Import`, `SQLCraft\Execution` | DELIMITER-aware state machine as a named, unit-testable class |
| `h()` (HTML escape) | `include/html.inc.php` | *(no equivalent — out of scope)* | — | SQLCraft has zero rendering surface; this responsibility does not exist in the library at all |
| `q()` / value quoting for logging only | throughout | `QuotingInterface::quoteValue()` (logging/debug use only — execution always binds parameters) | `SQLCraft\Contracts\Platform` | Explicitly documented as non-execution-path; `SecurityValidator`/bound params are the real defense (15-security.md §3) |
| `compile.php` (single-file concatenation) | repo root | Composer PSR-4 autoloading | `composer.json` | No include-order dependency; class resolution by type |

---

## 1. Connection & Driver Selection

Adminer selects its single active driver from `$_GET[DRIVER]` during bootstrap (`bootstrap.inc.php:88`), and the MySQL driver file must be included last because it sets the fallback `JUSH` constant (`03-adminer-analysis.md` §1). Exactly one driver is live per PHP process; there is no supported way to talk to two engines from the same request.

SQLCraft inverts this entirely. `DriverRegistry` (`08-driver-architecture.md` §8) is a stateless lookup table populated at bootstrap with the five built-in v1 drivers (plus any third-party ones a consumer registers). `SQLCraftFactory::connect(string $driverName, ConnectionParameters $params)` (`18-public-api.md` §2.2) is the only place a driver name is resolved to a concrete `DriverInterface`, and it returns a fresh `DatabaseSession` every call — there is no limit on how many sessions, against how many engines, coexist in one process. A tool that diffs a MySQL schema against a PostgreSQL schema, or an AI agent enumerating three customer databases on three different engines, is a normal use case rather than something SQLCraft actively prevents.

The `attach()`-returns-error-string pattern (`is_object($return)` check at the call site, `driver.inc.php:38-41`) is replaced by a hard rule: every connection failure throws `ConnectionFailedException`/`AuthenticationException`/`ConnectionLostException` (`05-domain-model.md` §9). There is no boolean/string ambiguity for callers to defensively check.

---

## 2. Capability Detection

Adminer's `support(string $feature): bool` is one `preg_match` per driver file against a literal string of feature codes (`03-adminer-analysis.md` §4). There is no registry and no compile-time list — a typo in a call site (`support("desc_idx")` instead of `"descidx"`) compiles and silently evaluates `false` forever. Version gating for a specific feature (e.g. MySQL CHECK constraints landing at 8.0.16) is a `preg_match` against `server_info` duplicated wherever that gate is needed.

SQLCraft replaces the whole mechanism with the `Capability` backed enum, the immutable `CapabilitySet` value object, and `PlatformCapabilityResolver`, which evaluates version predicates against a `ServerVersion` VO once per platform (`09-capability-model.md` §2-4). Call sites use `$caps->require(Capability::CheckConstraints)` (throws a structured `CapabilityNotSupportedException` naming the capability, platform, and version) or `$caps->has(...)` for optional-path logic — never a free-text string. `Capability::cases()` makes the entire feature surface enumerable without reading source, which Adminer's approach cannot offer.

---

## 3. Metadata Introspection

Adminer's introspection surface — `tables_list()`, `table_status()`, `fields()`, `indexes()`, `foreign_keys()`, `triggers()`, `routines()`, `schemas()` — is a set of top-level functions redefined per driver file, each returning a bare `array` whose shape is documented only by a PHPStan `@phpstan-type` alias comment, never enforced at runtime (`03-adminer-analysis.md` §6). Different engines return subtly different key sets for "the same" concept (MySQL's `table_status()` has `Engine`/`Auto_increment`; PostgreSQL's has `Oid`/`nspname` and no `Engine` at all), so portable consumer code must defensively `isset()`-check every field.

SQLCraft's `SchemaManager` (`18-public-api.md` §2.2, §5) is the single facade over a set of typed inspector services (`ServerInspector`, `TableInspector`, `ColumnInspector`, `IndexInspector`, `ForeignKeyInspector`, `ViewInspector`, `RoutineInspector`, `TriggerInspector`, `SequenceInspector`, `CheckConstraintInspector`, `UserInspector` — `07-module-breakdown.md` §8, prompt brief). Each returns `readonly` DTOs (`TableStatus`, `ColumnMeta`, `IndexMeta`, `ForeignKeyMeta`, `TriggerMeta`, `RoutineMeta`, etc. — `05-domain-model.md` §4) with typed, nullable-where-genuinely-absent fields, hydrated by a per-platform `MetadataFactory` (`05-domain-model.md` §8) so the SQL-dialect concern (what query produces the raw rows) and the row-to-DTO mapping concern are cleanly separated. A missing PostgreSQL `Engine` concept is `?string $engine = null` on `TableStatus`, not a key that may or may not exist in an array.

---

## 4. SQL Dialect

Every driver file in Adminer defines its own top-level `idf_escape()`, `table()`, `limit()`, `limit1()` functions in the same implicit namespace; PHP's "only one driver file is ever included" behavior is what makes the redefinition-without-collision trick work (`03-adminer-analysis.md` §5). Nothing enforces that every driver implements every function with a compatible signature — a driver can silently omit `limit1()` and fall back to a default that may not even be correct for that engine.

SQLCraft's `PlatformInterface` is composed from segregated sub-interfaces — `QuotingInterface`, `PaginationInterface`, `TypeMapperInterface`, `DdlDialectInterface`, `IntrospectionDialectInterface` (`08-driver-architecture.md` §3) — each implemented once per concrete `*Platform` class (`MySQLPlatform`, `MariaDbPlatform`, `PostgreSQLPlatform`, `SqlitePlatform`, `SqlServerPlatform`). `AbstractPlatform` supplies SQL-standard defaults so a new platform only overrides what genuinely diverges (`08-driver-architecture.md` §4). The compiler and PHPStan enforce that every concrete platform satisfies the full interface contract — there is no "driver forgot to define a function" failure mode.

---

## 5. DDL Generation

Adminer's `create_sql()`/`alter_sql()`/`trigger_sql()` and the `recreate_table()` SQLite workaround are imperative string-concatenation functions with engine branches inline, mutating shared state via globals, impossible to unit-test without a live connection, and offering no way to preview generated DDL before running it (`13-ddl-services.md` §1.1, Option A).

SQLCraft's DDL layer uses immutable builder VOs (`CreateTableBuilder`, `AlterTableBuilder`, `DropTableBuilder`, `CreateIndexBuilder`, and 15+ others — `13-ddl-services.md` §2) whose `toSql(DdlDialectInterface): list<string>` method is pure and testable with a mocked platform, and whose `execute(ConnectionInterface): void` method routes through `QueryExecutor::executeDdl()` so events fire and the metadata cache invalidates consistently (`13-ddl-services.md` §2.2). SQLite's table-recreation requirement — the hardest single case Adminer has to handle — becomes a named `TableRecreationStrategy` invoked by `SqlitePlatform`'s `renderAlterTable()`, not an inline procedural function (`13-ddl-services.md` §5, referenced). Builders return `list<string>` rather than a single string precisely because some engines (PostgreSQL multi-clause ALTER, SQLite recreation) genuinely require multiple statements — Adminer's single-string return type obscures this.

---

## 6. Data Operations

`SqlDriver::select()`/`insert()`/`update()`/`delete()`/`insertUpdate()` (`include/driver.inc.php:86-104` and surrounding) provide default implementations that call the free functions `limit()` and `table()` — meaning the "default" is only correct if the concrete driver file has separately and compatibly defined those functions, an implicit contract enforced by nothing (`03-adminer-analysis.md` §5).

SQLCraft's `SelectQuery` is an immutable VO (table, columns, WHERE conditions, ORDER BY, GROUP BY, LIMIT/OFFSET) rendered by `SelectQueryRenderer` into a `{sql, params}` pair, with every WHERE value collected as a bound parameter and every operator validated against the platform's allowlist at VO construction (`12-query-engine.md` §7, `15-security.md` §5.1). `QueryExecutor` (`12-query-engine.md` §2) is the single place that actually calls `ConnectionInterface::execute()`/`query()` on behalf of application code, streaming by default (`$buffered = false`) so browsing a million-row table does not require a million rows in PHP memory at once (`12-query-engine.md` §3).

---

## 7. Import / Export

Adminer's `dump.inc.php` mixes SQL/CSV generation with direct `echo`/`print` to `php://output`, coupled to HTTP response headers and streaming flush timing — it cannot be unit-tested or redirected to anything other than the current HTTP response without significant surgery (`03-adminer-analysis.md` §8, `02-guiding-principles.md` §1/§8).

SQLCraft's `Exporter` accepts a `ConnectionInterface` + scope + format and writes to *any* injected writable stream — never assuming HTTP exists (`07-module-breakdown.md` §10, `18-public-api.md` §3.8). Format plugins (SQL/CSV/TSV/JSON/XML) implement `FormatWriterInterface`; output sinks (file, `php://temp`, a PSR-7-independent stream resource) are fully decoupled from format. `Importer` mirrors this on the read side, using `StatementSplitterInterface` (a DELIMITER-aware state machine, `12-query-engine.md` §4.1) and `BatchExecutor` to stream statement-by-statement rather than loading a whole file into memory, emitting `ImportProgressEvent` periodically instead of Adminer's ad-hoc progress reporting (`18-public-api.md` §3.9, `16-events.md` §5.5).

---

## 8. Plugin System

`Plugins::__call()` dispatches by reflecting over every public method of a fresh `Adminer` instance, building a `$hooks[$methodName] => [plugin, ...]` map, and choosing short-circuit vs append dispatch based on a hardcoded five-entry static array (`plugins.inc.php:74-91`, `03-adminer-analysis.md` §2.5/§7). The critical smell: a caller cannot tell from `adminer()->dumpFormat()` alone whether the call is short-circuit or append without reading `Plugins`' private state.

SQLCraft has no magic dispatch at all. Cross-cutting behavior is PSR-14 events (`16-events.md`) split into `ObservabilityEvent` (fire-and-forget, `readonly`, cannot influence behavior) and `InterceptionEvent` (mutable, cancellable via `StoppableEventInterface`, can call `replaceSql()`/`cancel()`). Every hookable moment is a named, IDE-enumerable class — `BeforeQueryExecuted`, `AfterDdlExecuted`, `BeforeSchemaChange`, etc. (27 events cataloged in `16-events.md` §5) — with an explicit priority band convention (security > cache > business logic > observability, `16-events.md` §7) replacing "registration order, no control." Extension beyond events is via explicit interface implementation and DI substitution (a consumer swaps in their own `SchemaInspectorInterface` implementation), never directory scanning or `__call`.

---

## 9. Customization Hooks — Adminer Class Methods, Individually Mapped

Adminer's `Adminer` class mixes pure-logic hooks and UI/HTML hooks as ordinary public methods with no type-level distinction (`03-adminer-analysis.md` §2.4). SQLCraft has no UI hooks at all (no rendering surface exists to hook into); the logic hooks map to concrete extension seams:

| Adminer hook | SQLCraft mechanism |
|---|---|
| `credentials()`, `loginForm()`, `loginFormField()` | `CredentialProvider` interface (injected at connection time, `06-connection-layer.md`) |
| `databases()`, `schemas()` | Custom `SchemaInspectorInterface`/`MetadataServiceInterface` implementation swapped via DI |
| `operators()`, `selectQueryBuild()` | `PlatformInterface::getOperators()` + `SelectQuery`/`SelectQueryRenderer` composition |
| `processInput()`, `fieldName()`, `tableName()` | `BeforeQueryExecuted`/`BeforeSchemaChange` interception events (mutate via `replaceSql()`) |
| `dumpTable()`, `dumpData()`, `dumpFormat()`, `dumpOutput()` | `FormatWriterInterface` implementations registered with `Exporter` |
| Import-related hooks (implicit in `dump.inc.php` handling) | `ImportSourceInterface` implementations registered with `Importer` |
| `head()`, `bodyClass()`, `css()`, `pluginsLinks()`, `name()` (UI/HTML hooks) | **No equivalent — out of scope.** SQLCraft has zero rendering surface; these hooks describe a responsibility SQLCraft explicitly does not own (`02-guiding-principles.md` §1) |

---

## 10. Global State

Adminer's four mechanisms — `define()` constants (`HTTPS`, `JUSH`, `SERVER`, `DB`, `ME`, `DRIVER`), nested `$_SESSION` arrays, static class properties (`SqlDb::$instance`, `Adminer::$instance`), and singleton accessor functions (`connection()`, `driver()`, `adminer()`) — together mean any function anywhere in 40+ include files can call `connection()->query(...)` with zero declared dependency; the call graph is invisible from a signature (`03-adminer-analysis.md` §3).

SQLCraft's answer is unconditional: constructor-injected `ConnectionInterface`/`PlatformInterface` everywhere, contextual VOs passed as explicit arguments, and a composition root (`SQLCraftFactory`) that is the *only* place a concrete adapter class is named (`18-public-api.md` §2.2). `DriverRegistry` is the sole intentional exception to "no static state," and it is a stateless lookup table of driver *factories* (analogous to a service locator populated once at bootstrap), not mutable session or connection state — see `18-public-api.md` §1 for why this specific exception was accepted rather than eliminated entirely.

---

## What Adminer Has That SQLCraft v1 Defers

### Intentionally post-v1 (UI/web concerns — permanently out of SQLCraft's scope, not merely deferred)

| Feature | Why excluded |
|---|---|
| Schema diagram *rendering* (the visual ERD canvas) | SQLCraft returns the graph data (`TableCollection` + `ForeignKeyMeta[]`) per `04-feature-inventory.md` §19; rendering pixels is a consumer/UI concern by design, not a gap |
| Permanent login (XXTEA-encrypted cookie) | Cookie/session management is explicitly excluded — `15-security.md` §9 |
| Brute-force login throttle | Rate-limiting is infrastructure/middleware, not a library concern — `15-security.md` §9 |
| JUSH syntax highlighting (SQL editor color-coding) | A client-side/UI presentation concern; SQLCraft emits no markup of any kind |
| Visual FK navigation (clickable links between rows) | The underlying capability (FK metadata, backward-key derivation) is fully modeled in `04-feature-inventory.md` §14; only the clickable-link UI affordance is out of scope |
| CSRF token generation/verification | HTTP-layer concern; `15-security.md` §9 explicitly assigns this to the consumer |
| HTML escaping (`h()`) | No rendering surface exists to escape output for |

### Genuine capability gaps to track on the roadmap (not UI — real missing functionality)

| Feature | Status |
|---|---|
| Schema diff / DDL migration generation (compare two `TableStatus` snapshots, emit ALTER statements) | Named as a `Schema` bounded-context responsibility in `06-package-architecture.md` §3 ("high-level schema comparison and diff") but no document yet specifies the diff algorithm or its DDL-generation completeness — tracked as an open question in `24-open-questions.md` §4 |
| Cross-table search (Adminer's "search across tables" fan-out) | Mapped to a capability (`Capability::CrossTableSearch`, `04-feature-inventory.md` §14) but no dedicated service/interface has been designed yet |
| Query history persistence | Deliberately excluded as a SQLCraft-owned concern (`04-feature-inventory.md` §15) — `QueryHistoryInterface` exists but ships only `NullQueryHistory`/`InMemoryQueryHistory`/`CallbackQueryHistory`; a durable backend is a consumer's job, not a genuine gap in SQLCraft's surface |
| BLOB download as a discrete HTTP-friendly operation | The streaming primitive exists (`Capability::BlobStreaming`) but no document specifies a ready-made "download this BLOB as a stream with correct MIME sniffing" convenience method — likely a thin wrapper consumers write themselves, flagged here for completeness |


