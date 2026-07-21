# 17 — Plugin / Extension System

> **Status:** Design draft
> **Scope:** `SQLCraft\Contracts` extension points — why Adminer's `__call` model fails for a library, SQLCraft's three-mechanism extension model, complete Adminer-hook-to-SQLCraft mapping table, `PlatformExtension` decoration, extension discovery, API stability promises, explicit rejections
> **Depends on:** 05-domain-model.md (exception hierarchy, DTOs), 08-driver-architecture.md (DriverInterface, PlatformInterface), 09-capability-model.md (Capability), 10-connection-layer.md (ConnectionInterface, CredentialProviderInterface), 11-schema-services.md (SchemaManager inspector interfaces), 12-query-engine.md (QueryExecutor, QueryHistoryInterface), 13-ddl-services.md (DdlDialectInterface), 14-import-export.md (FormatWriterInterface, FormatReaderInterface, ImportSourceInterface, SinkInterface), 16-events.md (PSR-14 event catalog, InterceptionEvent)
> **Namespace root:** `SQLCraft\Contracts` (extension interfaces); `SQLCraft\Extension` (registry helpers)

---

## 1. Why Adminer's Plugin Model Fails for a Library

Adminer's plugin mechanism:

```php
// Adminer plugin dispatch — do NOT copy
class Adminer {
    function __call(string $name, array $args): mixed {
        $return = null;
        foreach ($this->plugins as $plugin) {
            if (method_exists($plugin, $name)) {
                $value = call_user_func_array([$plugin, $name], $args);
                // Append mode: array union for specific methods
                if (in_array($name, ['dumpFormat', 'dumpOutput', 'editRowPrint',
                                      'editFunctions', 'config'])) {
                    $return = $value + (array) $return;
                } elseif ($value !== null) {
                    // Short-circuit mode: first non-null wins
                    return $value;
                }
            }
        }
        return $return;
    }
}
```

This design was appropriate for Adminer's web-application context in 2009, but it has fundamental problems for a modern PHP 8.4 library:

1. **Magic `__call` is invisible to static analysis.** PHPStan and Psalm cannot track what `$adminer->credentials()` returns from a plugin. Every call returns `mixed`. IDE autocompletion does not work for plugins.
2. **No type safety for plugin return values.** Returning the wrong type from a plugin method silently corrupts behavior (e.g., returning a string from an append-mode method like `dumpFormat` merges it with an array via `+`, causing PHP to silently coerce it).
3. **Implicit short-circuit vs append mode.** The distinction is hardcoded in one list inside `__call`. A plugin author must read source to know if their hook short-circuits others or merges. This is never expressed in the interface.
4. **Every hookable method must exist on the `Adminer` class.** Adding a new extension point requires touching core. There is no way to create a new extension point in a plugin without also modifying `Adminer`.
5. **UI hooks dominate the list.** Adminer has ~60+ hookable methods; more than 40 are UI rendering hooks (`head`, `bodyClass`, `loginForm`, `tablePrint`, `navigation`, etc.). These are entirely meaningless for SQLCraft, which has no HTML, no HTTP response, no browser.
6. **Plugin interactions are invisible.** If plugin A short-circuits `credentials()` and plugin B also defines `credentials()`, plugin B is silently skipped. There is no way for plugin B to know this happened, and no way to declare ordering intent.
7. **No cross-cutting hooks.** Intercepting every query requires implementing `selectQuery`, `query`, `editRows`, and every other method that executes SQL separately. A single "before any SQL executes" hook does not exist.
8. **No DI integration.** Adminer scans an `adminer-plugins/` directory and instantiates plugins with no constructor injection. In a DI-framework world this is a dead end — plugins cannot receive their own dependencies from a service container.

**For SQLCraft:** The web-rendering hooks (40+ of the ~60) are simply not applicable. Modeling them would be dead surface area. The remaining ~20 logic/data hooks are better served by more targeted, typed mechanisms described in §2.

---

## 2. SQLCraft's Extension Model — Three Mechanisms

SQLCraft deliberately does not build one unified "plugin" abstraction. Adminer's single mechanism trying to serve every kind of extension is precisely what produced the ambiguity in §1. Instead, three distinct, purpose-built mechanisms are used together, each suited to a different kind of extension need:

| Mechanism | Best suited for | Example |
|-----------|-----------------|---------|
| **PSR-14 events** (16-events.md) | Cross-cutting observation and interception that applies across many operations | Logging every query; vetoing writes in read-only mode; tenant-scoping every SELECT |
| **Swappable service implementations via DI** | Replacing an entire algorithm/data source wholesale | A consumer's own `ServerInspectorInterface` that reads from a read-replica-aware routing layer instead of the primary connection |
| **Explicit extension interfaces** | Specific, well-bounded customization points that are neither cross-cutting nor "replace the whole service" | `CredentialProviderInterface`, `FormatWriterInterface`, `ImportSourceInterface`, `DriverRegistryInterface` |

### 2.1 Mechanism 1 — PSR-14 Events (Primary Extension Primitive)

Already fully specified in 16-events.md. This is the default answer for "how do I add behavior around an operation without forking SQLCraft." Recap of the relevant contract for this document:

- **Observability events** (`AfterQueryExecuted`, `AfterDdlExecuted`, `ExportFinishedEvent`, etc.) — fire-and-forget, cannot alter behavior. Used for logging, metrics, audit trails.
- **Interception events** (`BeforeQueryExecuted`, `BeforeSchemaChange`, `BeforeConnectionOpened`, `BeforeTransactionBegan`) — fired before an operation; a listener can call `$event->cancel()` to veto, or (for `BeforeQueryExecuted` specifically) call `$event->replaceSql()` to rewrite the statement.

Any extension whose job is "run some code whenever X happens across the whole library" is implemented as an event listener, registered with whatever PSR-14 dispatcher the consumer's application already uses (or SQLCraft's zero-dependency `SimpleEventDispatcher`, 16-events.md §2).

### 2.2 Mechanism 2 — Swappable Service Implementations via DI

Every stateful or algorithmic SQLCraft service is defined as an interface first (05-domain-model.md §7, 11-schema-services.md §3, 12-query-engine.md, 13-ddl-services.md §3). A consumer who wants fundamentally different behavior — not "run extra code around the existing behavior" but "replace the behavior entirely" — implements the interface and wires their implementation into the DI container in place of SQLCraft's default.

```php
// Consumer wants table listing to go through a caching read-replica router
// instead of SQLCraft's default TableInspector.
final class ReplicaAwareTableInspector implements TableInspectorInterface
{
    public function __construct(
        private readonly TableInspectorInterface $primary,
        private readonly ReplicaRouter            $router,
    ) {}

    public function getTables(ConnectionInterface $conn, ?string $schema = null): TableCollection
    {
        $replicaConn = $this->router->routeReadOnly($conn);
        return $this->primary->getTables($replicaConn, $schema);
    }

    // ... delegate remaining interface methods
}

// DI container wiring (illustrative, framework-agnostic pseudo-config)
$container->set(TableInspectorInterface::class, ReplicaAwareTableInspector::class);
```

This works because every SQLCraft service constructor accepts interfaces, never concrete classes (05-domain-model.md §7: "Services are defined as interfaces in `Contracts`; implementations live in their respective bounded contexts"). No SQLCraft internals need to know the swap happened. `SchemaManagerFactory` (11-schema-services.md §6) and equivalent factories elsewhere accept overrides for exactly this purpose.

This mechanism directly replaces the *intended use case* of Adminer's short-circuit plugin methods (e.g., a plugin fully overriding `databases()` behavior) but does so with compile-time type checking and constructor-injected dependencies instead of runtime `method_exists()` probing.

### 2.3 Mechanism 3 — Explicit Extension Interfaces

Some customization points don't fit "observe/intercept a generic operation" (mechanism 1) or "swap an entire service" (mechanism 2) — they are narrow, well-defined seams that SQLCraft itself designed as extension points from the start. These already exist throughout the other design documents:

| Interface | Defined in | Purpose |
|-----------|-----------|---------|
| `CredentialProviderInterface` | 10-connection-layer.md §4 | Supply credentials without SQLCraft ever storing secrets |
| `MetadataCacheInterface` | 11-schema-services.md §5 | Plug in PSR-6/PSR-16 caching for introspection results |
| `QueryHistoryInterface` | 12-query-engine.md §11 | Plug in a storage backend for executed-query history |
| `FormatWriterInterface` / `FormatReaderInterface` | 14-import-export.md §2.2, §7 | Add new export/import formats |
| `SinkInterface` | 14-import-export.md §2.1 | Add new export output targets |
| `ImportSourceInterface` | 14-import-export.md §6.1 | Add new import source types |
| `DriverInterface` / `DriverRegistryInterface` | 08-driver-architecture.md §2, §8 | Add support for a new database engine |
| `EventDispatcherInterface` (PSR-14) | 16-events.md §2 | Supply the event dispatcher implementation |
| `TransactionManagerInterface` | 12-query-engine.md §5 | Replace nested-transaction/savepoint behavior |

These interfaces are the SQLCraft-designed seams — narrower and more specific than a full service swap (mechanism 2), and not naturally expressible as a before/after event (mechanism 1) because they represent "supply a resource" or "supply a strategy" rather than "observe/intercept an event in time."

---

## 3. Logic Hooks From Adminer — Representative Mappings

Before the full ~60-row table (§6), a few representative examples illustrate how each mechanism is chosen:

**`afterConnect` → `ConnectionOpenedEvent` (mechanism 1).** Adminer's `afterConnect()` plugin hook runs custom SQL right after connecting (e.g., `SET NAMES utf8mb4`, session variable setup). SQLCraft fires `ConnectionOpenedEvent` (16-events.md §5.1) after every successful connect; a listener runs whatever post-connect statements it needs via the connection carried in the event payload. No dedicated interface needed — this is purely "run code after an event occurs."

**`databases()` override → custom `ServerInspectorInterface` (mechanism 2).** Adminer plugins can override `databases()` to change which databases are listed (e.g., filtering out system databases, or querying a catalog service instead of the live server). This is "replace an entire algorithm," so SQLCraft's answer is DI substitution of `ServerInspectorInterface`, not an event.

**`operators()` → `PlatformInterface::getOperators()` (not a hook at all — it's core platform data).** Adminer's `operators()` plugin hook lets a plugin change which WHERE operators are offered per column type. In SQLCraft this is not an extension point — it is core platform behavior already modeled as `PlatformInterface::getOperators()` (12-query-engine.md §7, referencing 15-security.md §4's operator allowlist). A consumer who wants different operators available implements mechanism 2 (a custom `PlatformInterface` decorator, §4) rather than a one-off hook.

**`dumpFormat` / `dumpOutput` → `FormatWriterInterface` / `SinkInterface` registries (mechanism 3).** These were Adminer's append-mode hooks specifically because multiple plugins could each contribute one additional format/output option, and the results needed merging. SQLCraft's `FormatRegistry` (14-import-export.md §7) is the typed, natural replacement — each format is a self-contained registration, no array-union merging logic required, no ambiguity about ordering.

**`importServerPath` → `ImportSourceInterface` (mechanism 3).** Adminer's plugin hook lets a plugin add a source for server-side SQL files (e.g., pulling import files from S3 instead of local disk). SQLCraft models this directly as an `ImportSourceInterface` implementation (14-import-export.md §6.1) — no separate hook needed, since the interface itself was designed to be pluggable from day one.

**`processList` / `showVariables` / `showStatus` → their respective `ServerInspectorInterface` methods (mechanism 2, or no extension needed at all).** These were Adminer hooks because different plugins added engine-specific twists to server monitoring displays. In SQLCraft, `ServerInspectorInterface::getProcessList()` / `getVariables()` / `getStatus()` are the platform-appropriate implementations already dispatched per-engine (11-schema-services.md §3.1); a consumer needing custom behavior swaps the whole `ServerInspectorInterface` implementation via DI.

---

## 4. The `PlatformExtension` / Decoration Model

Adminer's "flavor" concept (e.g., MariaDB as a flavor of MySQL, 08-driver-architecture.md §6) covers engine *family* differences that SQLCraft's built-in platforms already model via subclassing. But sometimes a consumer wants to tweak **their own** behavior on top of a platform without subclassing SQLCraft's concrete class (which would couple them to SQLCraft's internal implementation details and break on upgrades).

**The problem with subclassing a concrete platform:** `final class MySQLPlatform` (per 02-guiding-principles.md's "final classes by default" convention) cannot be subclassed at all. Even if it could, subclassing ties the consumer to internal method signatures that are not part of the stable public contract.

**The solution — decoration against the interface:**

```php
namespace Acme\App\SqlCraft;

use SQLCraft\Contracts\Platform\PlatformInterface;

/**
 * Decorates any PlatformInterface to force a specific charset default,
 * without needing SQLCraft to expose a hook for "override default charset."
 */
final class ForcedCharsetPlatform implements PlatformInterface
{
    public function __construct(
        private readonly PlatformInterface $inner,
        private readonly string            $forcedCharset,
    ) {}

    public function getDefaultCharset(): ?string
    {
        return $this->forcedCharset; // override
    }

    // All other PlatformInterface methods delegate transparently:
    public function quoteIdentifier(Identifier $identifier): string { return $this->inner->quoteIdentifier($identifier); }
    public function getName(): string { return $this->inner->getName(); }
    // ... remaining ~20 interface methods delegate to $this->inner
}
```

This is the **decorator pattern applied to `PlatformInterface`**, the same pattern already used for connection pooling (10-connection-layer.md §13, `ConnectionPoolInterface` as a seam) and retry logic (10-connection-layer.md §6.3, `RetryDecorator`). It requires no SQLCraft-side hook registration — it is ordinary OOP composition enabled entirely by the fact that `PlatformInterface` is, per 08-driver-architecture.md, a segregated interface that any class can implement.

**Where this is registered:** wherever the consumer's DI container constructs a `DriverInterface`, they wrap the platform it returns:

```php
final class DecoratingMysqlDriver implements DriverInterface
{
    public function __construct(private readonly MySQLDriver $inner) {}

    public function getPlatform(ConnectionInterface $connection): PlatformInterface
    {
        return new ForcedCharsetPlatform($this->inner->getPlatform($connection), forcedCharset: 'utf8mb4');
    }

    // delegate buildDsn(), connect(), getName(), getPdoDriverNames() to $this->inner
}

$registry->register(new DecoratingMysqlDriver(new MySQLDriver()));
```

**When to use decoration vs a full custom `DriverInterface`:** decoration is appropriate for small, targeted behavior tweaks (a different default charset, a different quoting edge case, an added capability). A genuinely new engine (§7) always warrants a full `DriverInterface` + `PlatformInterface` implementation, not decoration of an existing one.

---

## 5. Extension Discovery — Explicit Registration Only

Adminer's `adminer-plugins/` directory is scanned automatically at bootstrap: any PHP file dropped in that directory is `include`d and its class is instantiated, purely by filesystem convention. SQLCraft rejects this model entirely:

- **No directory scanning.** SQLCraft never inspects a filesystem path looking for extension classes. There is no `SQLCraft\Extension\autoDiscover()` function and none will be added.
- **No autoloading magic beyond Composer's PSR-4.** A third-party extension package is loaded exactly like any other Composer dependency — `composer require acme/sqlcraft-duckdb` — and its classes become available via ordinary autoloading. SQLCraft does nothing special to "detect" the presence of that package.
- **All registration is explicit DI wiring**, performed by the consumer application, not by SQLCraft. This applies uniformly to all three mechanisms from §2:
  - PSR-14 listeners: registered with the dispatcher via the framework's normal listener registration (Symfony service tags, Laravel `EventServiceProvider`, or direct `SimpleListenerProvider::listen()` calls — 16-events.md §6).
  - Service swaps: registered as DI container bindings (`$container->set(Interface::class, Implementation::class)`).
  - Format/source/driver registries: explicit `FormatRegistry::registerWriter(...)`, `$registry->register(...)` calls at bootstrap, typically inside the consumer's own service provider / container configuration class.

**Rationale:** an autoloading, auto-scanning plugin directory is a security and predictability liability for a library (arbitrary code execution merely by placing a file in a directory; no visibility into what actually loaded without reading the filesystem). It is also fundamentally incompatible with dependency injection, since scanned plugins receive no constructor-injected dependencies. Explicit registration means: (1) every active extension is visible by reading the consumer's own bootstrap/container configuration code, (2) extensions can receive their own dependencies through the same DI container as everything else, (3) there is no ambient "plugins directory" convention that behaves differently across deployment environments.

**How a framework's service container wires plugins** (illustrative, framework-agnostic):

```php
// Consumer's own ServiceProvider / container configuration — not SQLCraft code
final class SqlCraftServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        // Mechanism 3: register a custom credential provider
        $container->set(CredentialProviderInterface::class, VaultCredentialProvider::class);

        // Mechanism 2: swap the default table inspector
        $container->set(TableInspectorInterface::class, ReplicaAwareTableInspector::class);

        // Mechanism 3: register an extra export format
        $container->get(FormatRegistry::class)->registerWriter(new XlsxFormatWriter());

        // Mechanism 1: register event listeners
        $container->get(ListenerProviderInterface::class)->listen(
            AfterQueryExecuted::class,
            $container->get(QueryAuditListener::class)->onQueryExecuted(...),
        );

        // Third-party driver registration
        $container->get(DriverRegistry::class)->register($container->get(DuckDbDriver::class));
    }
}
```

---

## 6. Complete Adminer Hook → SQLCraft Mechanism Mapping

Every hookable method from the `Adminer` class plugin surface, mapped to its SQLCraft equivalent. "N/A — render concern" marks the pure UI/web hooks that SQLCraft deliberately does not model at all, per the task's explicit exclusion list.

| Adminer hook | SQLCraft mechanism |
|---|---|
| `credentials` | `CredentialProviderInterface` (mechanism 3) — 10-connection-layer.md §4 |
| `connectSsl` | `SslOptions` VO on `ConnectionParams` (not a hook — structured config) — 10-connection-layer.md §3 |
| `permanentLogin` | N/A — web-app session/cookie concern; SQLCraft has no session layer |
| `bruteForceKey` | N/A — web-app brute-force-protection concern; out of SQLCraft's scope (consumer's auth layer) |
| `serverName` | N/A — render concern (display label for a server in a picker UI) |
| `database` | Not a hook — `ConnectionParams::$database`, set at connection time (10-connection-layer.md §3) |
| `databases` | `ServerInspectorInterface::getDatabases()` — swap via DI (mechanism 2) — 11-schema-services.md §3.1 |
| `pluginsLinks` | N/A — render concern (UI nav links for installed plugins) |
| `operators` | `PlatformInterface::getOperators()` — core platform data, not a hook — 12-query-engine.md §7 |
| `schemas` | `DatabaseInspectorInterface::getSchemas()` — swap via DI (mechanism 2) — 11-schema-services.md §3.2 |
| `queryTimeout` | `ImportOptions::$statementTimeoutMs` / `QueryExecutor::queryWithTimeout()` — 12-query-engine.md §10, 14-import-export.md §6.3 |
| `afterConnect` | `ConnectionOpenedEvent` listener (mechanism 1) — 16-events.md §5.1 |
| `headers` | N/A — render concern (HTTP response headers) |
| `csp` | N/A — render concern (Content-Security-Policy header) |
| `head` | N/A — render concern (`<head>` HTML) |
| `bodyClass` | N/A — render concern |
| `cssLinks` | N/A — render concern |
| `loginForm` | N/A — render concern |
| `loginFormField` | N/A — render concern |
| `login` | `CredentialProviderInterface` + `ConnectionFactory::connect()` (mechanism 3) — 10-connection-layer.md §4–5 |
| `tableName` | N/A — render concern (display-name formatting for a table in UI) |
| `fieldName` | N/A — render concern (display-name formatting for a column in UI) |
| `processInput` | N/A — web form input processing; out of scope (no forms in SQLCraft) |
| `processInputs` | N/A — web form input processing; out of scope |
| `editFunctions` | N/A — render concern (edit-form function dropdown, e.g. `NOW()` picker) |
| `editInput` | N/A — render concern (edit-form input widget) |
| `editHint` | N/A — render concern (edit-form inline hint text) |
| `selectLinks` | N/A — render concern (browse-table action links) |
| `tablePrint` | N/A — render concern |
| `tablesPrint` | N/A — render concern |
| `tableStructurePrint` | N/A — render concern |
| `tableStructureProcesses` | N/A — render concern (UI section for related processes) |
| `tableStructureBeforeColumns` | N/A — render concern |
| `tableStructureAfterColumns` | N/A — render concern |
| `tableStructureAfterConstraints` | N/A — render concern |
| `backwardKeys` | `ForeignKeyInspectorInterface::getReferencingKeys()` — swap via DI (mechanism 2) — 11-schema-services.md §3.6 |
| `backwardKeysPrint` | N/A — render concern |
| `selectQuery` | `BeforeQueryExecuted` interception event, `replaceSql()` (mechanism 1) — 16-events.md §5.2 |
| `selectQueryBuild` | `SelectQuery` VO construction (12-query-engine.md §7) — consumer builds it directly, not a hook |
| `selectQueryPrint` | N/A — render concern |
| `selectColumnsProcess` | `SelectQuery::$columns` construction — consumer-side VO assembly, not a hook |
| `selectSearchProcess` | `SelectQuery::$where` construction — consumer-side VO assembly, not a hook |
| `selectOrderProcess` | `SelectQuery::$orderBy` construction — consumer-side VO assembly, not a hook |
| `selectLimitProcess` | `PaginationParams` construction (12-query-engine.md §6.1) — consumer-side VO assembly |
| `selectLengthProcess` | `DumpOptions::$batchSize` / query `LIMIT` value — consumer-side parameter |
| `selectActionPrint` | N/A — render concern |
| `selectCommandPrint` | N/A — render concern |
| `selectImportPrint` | N/A — render concern |
| `selectEmailProcess` | N/A — web form email-notify feature; out of scope |
| `selectEmailPrint` | N/A — render concern |
| `rowDescriptions` | N/A — render concern (inline hint text describing a row) |
| `dumpFormat` | `FormatRegistry::registerWriter()` (mechanism 3) — 14-import-export.md §7 |
| `dumpOutput` | `SinkInterface` implementations (mechanism 3) — 14-import-export.md §2.1 |
| `dumpDatabase` | `Exporter::export()` with `DumpScope::database()` — 14-import-export.md §2.3, §2.5 |
| `dumpTable` | `TableDumper::dump()` — 14-import-export.md §2.4 |
| `dumpData` | `FormatWriterInterface::writeRows()` — 14-import-export.md §2.2 |
| `dumpHeaders` | `FormatWriterInterface::writeHeader()` / `writeTableHeader()` — 14-import-export.md §2.2 |
| `dumpFooters` | `FormatWriterInterface::writeFooter()` / `writeTableFooter()` — 14-import-export.md §2.2 |
| `homepage` | N/A — render concern |
| `navigation` | N/A — render concern |
| `privileges` | `PrivilegeInspectorInterface::getPrivileges()` — swap via DI (mechanism 2) — 11-schema-services.md §3.12 |
| `importServerPath` | `ImportSourceInterface` (mechanism 3) — 14-import-export.md §6.1 |
| `processList` | `ServerInspectorInterface::getProcessList()` — swap via DI (mechanism 2) — 11-schema-services.md §3.1 |
| `showVariables` | `ServerInspectorInterface::getVariables()` — swap via DI (mechanism 2) — 11-schema-services.md §3.1 |
| `showStatus` | `ServerInspectorInterface::getStatus()` — swap via DI (mechanism 2) — 11-schema-services.md §3.1 |
| `killProcess` | Platform-specific `ConnectionInterface`/`ServerInspectorInterface` method issuing the engine's KILL syntax; capability-gated on `Capability::Kill` (09-capability-model.md §6) |
| `rowCount` | `TableStatus::$rows` + `Paginator`'s count strategy (12-query-engine.md §6.3) |
| `config` | DI container configuration itself — not a runtime hook; SQLCraft has no equivalent because configuration is the consumer's container, not a plugin-appended array |

**Tally:** ~20 of the ~60 hooks map to a genuine SQLCraft logic mechanism (event, swappable interface, or explicit extension interface); ~6 map to "not a hook, just a VO/constructor parameter you set directly"; ~4 are entirely out of scope (web forms, sessions, brute-force protection — application concerns SQLCraft does not own); the remaining ~30 are render/UI hooks explicitly excluded per this document's scope.

---

## 7. Third-Party Driver Integration — The Primary "Plugin"

For SQLCraft, adding support for a new database engine is the highest-value and most common form of extension — more consequential than any of the logic hooks in §6. This is already specified in full in 08-driver-architecture.md §9 (the DuckDB walkthrough); this section summarizes what a driver package ships from the extension-system point of view.

A third-party driver package (e.g., `acme/sqlcraft-duckdb`) ships:

1. **A `DriverInterface` implementation** — the connection factory (08-driver-architecture.md §2).
2. **A `PlatformInterface` implementation** (typically extending `AbstractPlatform`) — quoting, pagination, type mapping, DDL dialect, introspection dialect (08-driver-architecture.md §3–4, 13-ddl-services.md §3).
3. **A capability matrix** — `buildCapabilityMatrix()` override declaring which `Capability` enum cases apply and any version gating (09-capability-model.md §4, §8).
4. **A `MetadataFactory` implementation** — row-to-DTO hydration for that engine's introspection query result shapes (05-domain-model.md §8).
5. **Optionally, extended capabilities** via `ExtendedCapability` for engine-specific features that don't fit the core `Capability` enum (09-capability-model.md §8) — e.g., DuckDB's Parquet import support.
6. **A `composer.json` requiring `vendor/sqlcraft`** with a compatible version constraint, and depending on the specific PDO extension the driver needs (e.g., `ext-pdo_duckdb` in this example, though PHP's PDO driver ecosystem for exotic engines varies — some third-party engines require a custom PDO userspace driver or a non-PDO connection wrapped to satisfy `ConnectionInterface` directly, per 10-connection-layer.md §12's allowance for non-`PdoConnection`-based implementations).

**Registration remains explicit** per §5 — the consumer's application calls `$registry->register(new DuckDbDriver())` at bootstrap. SQLCraft's core package has zero awareness of third-party driver packages; there is no driver marketplace, no auto-discovery tag scanning (e.g., no reliance on Composer's `type` field to auto-register).

**What driver packages should NOT ship:** UI components, HTML templates, or anything resembling Adminer's per-driver `.inc.php` files that mixed dialect logic with page rendering. A SQLCraft driver package is pure logic — `Contracts\Driver`, `Contracts\Platform` implementations and nothing else. Any UI layer built on top of SQLCraft (out of SQLCraft's own scope entirely) is a separate concern for a separate package.

---

## 8. Plugin Compatibility and API Stability

Not every interface in SQLCraft carries the same stability guarantee. This section defines which extension interfaces are covered by semantic versioning (breaking changes only in major versions) and which are internal.

| Category | Examples | Stability promise |
|----------|---------|-------------------|
| **Stable public extension interfaces** | `CredentialProviderInterface`, `MetadataCacheInterface`, `QueryHistoryInterface`, `FormatWriterInterface`, `FormatReaderInterface`, `SinkInterface`, `ImportSourceInterface`, `DriverInterface`, `PlatformInterface` (and its segregated sub-interfaces), all `Contracts\Metadata\*InspectorInterface`, `EventDispatcherInterface` (PSR-14, external), all `SQLCraft\Events\*` event classes' public properties | SemVer: no breaking signature changes without a major version bump. New optional methods are added only via a new sub-interface (never added to an existing interface after 1.0, since that would break existing implementations) — see the Interface Evolution Policy below |
| **Stable public DTOs/VOs consumed by extensions** | `ColumnMeta`, `TableStatus`, `ForeignKeyMeta`, `Capability`, `CapabilitySet`, `ConnectionParams`, `Credential` | SemVer: constructor parameter removal/reordering is breaking; new *optional* (nullable, defaulted) constructor parameters are non-breaking as long as named-argument construction is used (PHP 8.4 convention throughout SQLCraft per 05-domain-model.md) |
| **Internal (marked `@internal`)** | `MetadataFactoryInterface` (05-domain-model.md §8: "not part of public API"), `PdoExceptionTranslator`, `TableRecreationStrategy` (13-ddl-services.md §5.2: "@internal — used only by SqlitePlatform"), concrete `PdoConnection`/`PdoPreparedStatement` | No stability promise. May change in any release, including patch releases. Extensions must never depend on these directly |
| **Internal registries used only at bootstrap** | `DriverRegistry`, `FormatRegistry` | The *registration methods* (`register()`, `registerWriter()`) are stable public API; internal storage structure is not |

### 8.1 Interface Evolution Policy

Because PHP interfaces cannot add a new method without breaking every existing implementer, SQLCraft's policy for extending a stable interface after 1.0 is:

1. **Prefer a new, separate interface** that existing implementations are not forced to implement (interface segregation, as already practiced for `PlatformInterface`'s sub-interfaces in 08-driver-architecture.md §3). A capability check (`instanceof`) at the call site determines whether the new interface is available.
2. **If a method must be added to an existing interface,** it happens only in a major version, with the change called out prominently in the upgrade guide, and (where feasible) a trait providing a default implementation is offered so existing implementers can adopt the trait rather than hand-write the new method.
3. **Never use optional interface methods via `method_exists()` checks** — that is precisely the runtime-reflection anti-pattern this document rejects in §9. If an implementer might or might not support a new method, that is expressed as a *new interface*, checked via `instanceof`, not as an optionally-implemented method on an old one.

### 8.2 What This Means for Third-Party Extension Authors

An author of a `FormatWriterInterface` implementation, a `DriverInterface` implementation, or an event listener can depend on SQLCraft's `^1.0` constraint with confidence that their code will not break on a `1.x` patch or minor release. A breaking change to any interface in the "stable public" tier is, by definition, a `2.0` event. Internal classes carry no such guarantee — an extension that reaches into `SQLCraft\Connection\Adapter\PdoConnection` directly (rather than depending on `ConnectionInterface`) is unsupported and may break on any release.

---

## 9. Explicit Rejections

To keep this design decision legible for future contributors who might be tempted to reintroduce Adminer-style patterns, the following are explicitly rejected and must not be reintroduced without a new ADR:

1. **Auto-scanning plugin directories.** No `SQLCraft\Extension\scanDirectory(string $path)` or equivalent. Extensions are loaded via Composer + explicit DI registration only (§5).
2. **Magic `__call` dispatch over a plugin chain.** No `Adminer`-style class that intercepts arbitrary method calls and forwards them to registered plugin objects. Every SQLCraft service has a concrete, typed interface; callers call named methods directly.
3. **Append-vs-short-circuit mode guessing.** No hardcoded list of "these method names merge results, these don't." Where merging behavior is needed (e.g., multiple registered export formats), it is expressed as an explicit registry (`FormatRegistry`) with an explicit `register()` call per item — never as an implicit array-union of plugin return values.
4. **Method-existence checks via reflection at runtime.** No `method_exists($plugin, $name)` or `is_callable()` probing to decide whether an object supports a behavior. Capability is always expressed through the type system: `instanceof SomeInterface`, or a `Capability` enum check (09-capability-model.md), never runtime reflection on an arbitrary object's method list.
5. **A single monolithic `Plugin` base class with 60 overridable methods.** There is no `SQLCraft\Plugin` abstract class that a consumer extends and selectively overrides. Each extension mechanism (§2) is used for exactly the kind of extension it fits; there is no one-size-fits-all base class encouraging consumers to override methods they don't need.
6. **Implicit registration ordering with no priority control for anything other than events.** Only the PSR-14 event system has an ordering concept (priority bands, 16-events.md §7). Service swaps (mechanism 2) and explicit extension interfaces (mechanism 3) have exactly one active implementation at a time by construction — there is no "list of table inspectors, first one wins" ambiguity to order.

---

## 10. Summary of Key Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Single vs multiple extension mechanisms | Three distinct mechanisms (events, DI swaps, explicit interfaces) | Adminer's single `__call` mechanism trying to serve every extension need produced ambiguous short-circuit/append semantics; splitting by extension *kind* removes the ambiguity |
| UI/render hooks | Not modeled at all | SQLCraft has no HTML/HTTP layer; ~30 of Adminer's ~60 hooks are pure render concerns with no SQLCraft equivalent |
| Cross-cutting observation/interception | PSR-14 events (16-events.md) | Already-specified, typed, IDE-discoverable; replaces per-operation hook proliferation |
| Wholesale behavior replacement | DI-based interface swapping | Type-safe, constructor-injectable, no runtime reflection; every SQLCraft service is interface-first (05-domain-model.md §7) |
| Narrow, SQLCraft-designed customization seams | Explicit extension interfaces (`CredentialProviderInterface`, `FormatWriterInterface`, etc.) | These are seams SQLCraft designed in from the start, documented in their owning chapters, not retrofitted as generic hooks |
| Engine-specific tweaks without subclassing | `PlatformInterface` decoration | SQLCraft's platforms are `final`; decoration against the interface achieves customization without coupling to internals or requiring SQLCraft to expose a hook for every conceivable tweak |
| Discovery | Explicit DI registration only; no directory scanning | Security, predictability, and DI compatibility; matches Composer's own "explicit dependency" philosophy |
| Primary "plugin" for engine support | Third-party `DriverInterface` + `PlatformInterface` packages | Already the highest-leverage extension point per 08-driver-architecture.md; this document defines its place in the broader extension taxonomy |
| API stability | Tiered: stable public interfaces (SemVer) vs `@internal` (no promise) | Lets extension authors know exactly what they can depend on without reading SQLCraft's source on every upgrade |
| Interface evolution | Prefer new segregated interfaces over adding methods to existing ones | PHP interfaces cannot gain methods without breaking implementers; segregation avoids forcing breaking changes |

