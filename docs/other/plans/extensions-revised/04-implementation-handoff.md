# SQLCraft Extension System — Implementation Handoff

> **Status:** Normative execution plan; no implementation has been performed
> **Date:** 2026-07-22
> **Audience:** Implementation agent with limited architectural judgment
> **Decision source:** `00-plugin-system-adr.md`
> **Architecture detail:** `01-extension-system-plan.md`
> **Parity source:** `02-adminer-5.5.0-hook-matrix.md`
> **Acceptance source:** `03-verification.md`

## 1. Purpose and Precedence

The ADR records why the extension model exists. It is not a file-by-file
implementation plan. `01-extension-system-plan.md` defines the target shape but
still requires sequencing and repository-specific instructions. This document is
the implementation handoff.

Apply documents in this order when wording conflicts:

1. `00-plugin-system-adr.md` for architectural boundaries.
2. This document for concrete implementation choices and task order.
3. `03-verification.md` for observable acceptance behavior.
4. `01-extension-system-plan.md` for supporting design explanation.
5. Superseded files in the parent `extensions/` directory are historical only.

Do not restore a rejected abstraction because an older plan names it.

## 2. Non-Negotiable Scope

Implement only typed SQLCraft extension seams that are live through
`SQLCraftBuilder -> SQLCraftFactory -> DatabaseSession`.

Do not implement:

- Adminer-compatible plugin objects or method-name dispatch;
- `ServiceProviderInterface`, `ExtensionBundle`, or auto-discovery;
- UI, HTML, HTTP, form, cookie, or session hooks;
- schema/database visibility filters presented as authorization;
- regex read-only enforcement or regex tenant SQL rewriting;
- an 85-method platform decorator;
- new dump formats, sinks, or sources already excluded by the revised plan;
- a second composition root alongside the builder;
- unrelated cleanup discovered while touching the listed files.

The repository has no real `v1.0.0` tag. Breaking correction of the current
untagged public API is allowed. Do not preserve a broken seam with a compatibility
adapter unless this document explicitly requires one.

## 3. Fixed Choices — Do Not Re-Decide

These choices close the remaining branches in the architecture plan.

### 3.1 Driver identifiers

`ConnectionParameters` accepts `string|DatabaseDriver|null` in its constructor
and stores `public ?string $driver` after lowercase normalization. The enum
remains a convenience for built-in callers; it must not close registration over
third-party driver names.

Canonical extension identifiers are trimmed lowercase non-empty strings. Reject
internal whitespace, control characters, and aliases that target another alias.
Alias targets must be canonical driver definitions present at `build()` time.
Connection labels are separate: trim them, reject blank/control/null-byte values,
but preserve case and internal spaces. The default connection label is the
normalized caller-requested driver or alias, not the canonical alias target.

### 3.2 Driver construction

`DriverDefinition` contains:

- canonical `name`;
- a driver factory closure invoked once per built factory snapshot;
- a metadata-inspector-set factory;
- an optional process-manager factory.

The driver factory receives the final `ConnectionEventDispatcherInterface`, which
avoids constructing built-in PDO drivers before event mode is known. Drivers must
not emit factory-owned before/open/failure events; the adapter is supplied for
connection-owned transaction and close events.

```php
final readonly class DriverDefinition
{
    /** @param \Closure(ConnectionEventDispatcherInterface): DriverInterface $driverFactory */
    public function __construct(
        public string $name,
        public \Closure $driverFactory,
        public MetadataInspectorSetFactoryInterface $metadata,
        public ?ProcessManagerFactoryInterface $processes = null,
    ) {}
}
```

`build()` invokes each closure once. It rejects a factory result whose
`DriverInterface::getName()` differs from the definition name. Session creation
rejects a connected platform whose name differs from the resolved canonical
definition. Aliases do not alter canonical identity.

### 3.3 Platform roles

`PlatformInterface` becomes the following small aggregate:

```php
interface PlatformInterface
{
    public function getName(): string;
    public function getFlavor(): ?string;
    public function getServerVersion(ConnectionInterface $connection): ServerVersion;
    public function getCapabilitySet(ServerVersion $version): CapabilitySet;
    public function getDefaultCharset(): ?string;
    public function getDefaultCollation(): ?string;
    public function supportsSchemas(): bool;

    public function ddl(): DdlDialectInterface;
    public function introspection(): IntrospectionDialectInterface;
    public function queryDialect(): QueryDialectInterface;
    public function quoting(): QuotingInterface;
    public function types(): TypeMapperInterface;
}
```

Add `QueryDialectInterface extends PaginationInterface` with exactly:

- `getKeywordList()`;
- `getOperators()`;
- `getSupportedAggregateFunctions()`;
- `getExplainSql()`;
- `wrapWithTimeout()`;
- inherited `applyPagination()` and `applySingleRowLimit()`.

Remove `getExplainSql()` and `wrapWithTimeout()` from
`IntrospectionDialectInterface`. They affect execution, not metadata.

Built-in platform classes stay monolithic in this phase. `AbstractPlatform`
implements every role interface and returns `$this` from the five role accessors.
Do not split each built-in platform into five classes. `ComposedPlatform` and
`PlatformRoles` provide true role composition for third-party engines and tests.

### 3.4 Metadata

Every canonical `DriverDefinition` owns its
`MetadataInspectorSetFactoryInterface`. There is no global builder method that
replaces the metadata factory for every driver. Global metadata customization is
an ordered decorator list applied to the selected driver's fresh set.

`SchemaManager`, `ExportSource`, `CsvImporter`, process listing, and privilege
security receive inspectors from the same decorated set instance for a session.
If the set has no privilege inspector, use `DenySecurityGuard`; do not construct
an independent default inspector.

### 3.5 Schema surface

`DatabaseSession::schema()` returns final concrete `SchemaManager`. Do not expand
`SchemaManagerInterface` to 34 methods and do not require extension authors to
implement the manager. Keep the current two-method interface only for its existing
comparison contract until a separate cleanup plan removes or renames it.

### 3.6 Process control

Keep `Capability::Kill`. Implement live process managers for MySQL/MariaDB,
PostgreSQL, and SQL Server. SQLite has neither the capability nor a manager.

`DatabaseSession::processes()` returns `ProcessManagerInterface` when the selected
definition supplies one. Otherwise it throws `CapabilityNotSupportedException`
without rendering or executing SQL.

Process IDs are positive integers. Convert numeric strings to integers after
strict validation. Never concatenate an unvalidated caller string into `KILL`
syntax. PostgreSQL uses a bound parameter. MySQL and SQL Server render only the
validated integer.

### 3.7 Formats

`FormatRegistry` stores factories, not adapter instances. It is session-scoped
because writer factories require the active connection. Every `getWriter()` and
`getReader()` call creates and validates a fresh adapter.

`Exporter` resolves a writer inside each `export()` call. `DatabaseSession`
exposes `formats(): FormatRegistry`, which makes registered readers reachable
without pretending the SQL statement `Importer` consumes row formats.

### 3.8 Events

Builder user modes remain mutually exclusive:

- SQLCraft-owned listeners registered with `listen()`;
- one external PSR-14 dispatcher registered with `eventDispatcher()`.

Core invariant listeners always run. In external mode, dispatch core listeners
first and then forward the same event object to the external dispatcher. Do not
require the external dispatcher to expose a listener provider.

`ConnectionOpenedEvent` fires only after credentials, low-level connection,
initializers, and `ConnectionManager` registration succeed.

### 3.9 Query transformation

Only `QueryInterceptorInterface` transforms SQL or parameters. Pre-query events
remain cancellable but become read-only.

The timeout wrapper runs before interception. For timeout execution,
`QueryRequest::$originalSql` is the caller SQL and `QueryRequest::$sql` starts as
the dialect-wrapped SQL. The interceptor chain runs exactly once.

Add a non-query timeout path. Do not continue using `queryWithTimeout()` for
`INSERT`, `UPDATE`, `DELETE`, or other non-row-returning statements.

## 4. Target Runtime Graph

`SQLCraftBuilder::build()` performs this order:

1. Validate mutually exclusive event modes.
2. Build the internal core listener provider and dispatcher.
3. Register cache invalidation and other required core listeners.
4. Build a separate SQLCraft-owned user dispatcher, select the external
   dispatcher, or select no downstream dispatcher.
5. Create the effective dispatcher as core-first plus the selected downstream,
   then create one `ConnectionEventDispatcher` from it.
6. Invoke every driver factory and validate its canonical name.
7. Freeze driver definitions, aliases, format factories, metadata decorators,
   initializers, interceptors, credentials, history, and cache into a new
   `SQLCraftFactory` snapshot.

`SQLCraftFactory::session()` performs this order:

1. Normalize the requested driver, validate the connection label, and reject a
   duplicate connection label before opening anything.
2. Resolve the alias to one canonical driver definition.
3. Resolve credentials when a credential key is supplied.
4. Build effective `ConnectionParameters`; never mutate the caller object.
5. Dispatch the cancellable before-open event.
6. Connect through the selected driver and verify platform identity.
7. Run connection initializers in registration order.
8. On initializer failure, close once, dispatch the initialization-failed event,
   and throw `ConnectionInitializationException` with the original exception.
9. Register the connection in `ConnectionManager`.
10. Dispatch `ConnectionOpenedEvent`.
11. Create one decorated `MetadataInspectorSet`.
12. Create one `QueryExecutor` using configured history, interceptors, and events.
13. Build `SchemaManager`, `ExportSource`, `DdlManager`, exporter, importer,
    security services, session format registry, and optional process manager.
    Privilege security uses the set's privilege inspector or `DenySecurityGuard`.
14. Return an immutable `DatabaseSession`.

No service in steps 11–13 may construct an independent metadata factory, format
registry, query executor, cache, history adapter, or dispatcher.

## 5. Work Package Order

Implement packages in order. Complete each package's tests before starting the
next. One package per commit is preferred. Do not parallelize packages that touch
`SQLCraftFactory`, `DatabaseSession`, `PlatformInterface`, or `QueryExecutor`.

---

## WP-0 — Baseline Truth and Dependencies

### Goal

Make package metadata and the Adminer baseline truthful before changing public
contracts.

### Modify

- `composer.json`
- `composer.lock`
- `CHANGELOG.md`
- `docs/other/plans/extensions/revised/02-adminer-5.5.0-hook-matrix.md` only if
  the vendored source has changed

### Actions

1. Move `psr/event-dispatcher` from `require-dev` to `require`.
2. Keep `psr/log` optional unless a shipped source class directly type-hints it.
3. Replace the dated, untagged `1.0.0` release claim with unreleased history.
   Remove compare/release links that require a nonexistent `v1.0.0` tag.
4. Add a test or tool that extracts public methods from
   `adminer/adminer/include/adminer.inc.php` and compares the exact ordered set to
   the 79 names in the parity matrix.
5. The drift check must also assert the exact five Adminer append hooks:
   `dumpFormat`, `dumpOutput`, `editRowPrint`, `editFunctions`, and `config`.

### Add

- `tests/Unit/Architecture/AdminerExtensionMatrixTest.php`

### Done when

- Composer installs with PSR-14 as a runtime dependency.
- Changelog claims no release tag that does not exist.
- Matrix test reports 79 unique hooks in exact source order and five exact append
  hooks.

---

## WP-1 — Foundational Contracts, Identifiers, and Exceptions

### Goal

Add small contracts needed by later packages and remove the built-in enum as an
extension barrier.

### Add

- `src/Contracts/Connection/ConnectionInitializerInterface.php`
- `src/Contracts/Execution/QueryInterceptorInterface.php`
- `src/Contracts/Execution/ProcessManagerInterface.php`
- `src/Contracts/Execution/ProcessManagerFactoryInterface.php`
- `src/Contracts/Metadata/MetadataInspectorSetFactoryInterface.php`
- `src/Contracts/Platform/QueryDialectInterface.php`
- `src/Driver/DriverDefinition.php`
- `src/Support/ExtensionIdentifier.php` marked `@internal`
- `src/Execution/QueryRequest.php`
- `src/Enums/QueryKind.php`
- `src/Exceptions/ExtensionConfigurationException.php`
- `src/Exceptions/DuplicateRegistrationException.php`
- `src/Exceptions/RegistrationNotFoundException.php`
- `src/Exceptions/CredentialNotFoundException.php`
- `src/Exceptions/ConnectionInitializationException.php`
- `src/Events/ConnectionInitializationFailedEvent.php`

### Modify

- `src/ValueObjects/ConnectionParameters.php`
- `src/Exceptions/DriverNotFoundException.php`
- `src/Exceptions/DriverMisconfiguredException.php`
- relevant unit tests under `tests/Unit/Contracts`, `tests/Unit/ValueObjects`,
  `tests/Unit/Exceptions`, and `tests/Unit/Events`

### Required signatures

```php
interface ConnectionInitializerInterface
{
    public function initialize(
        ConnectionInterface $connection,
        ConnectionParameters $parameters,
    ): void;
}

interface QueryInterceptorInterface
{
    public function intercept(QueryRequest $request): QueryRequest;
}

interface ProcessManagerInterface
{
    public function list(): ProcessCollection;
    public function kill(string|int $processId): void;
}

interface ProcessManagerFactoryInterface
{
    public function create(
        ConnectionInterface $connection,
        ServerInspectorInterface $server,
        QueryExecutorInterface $executor,
    ): ProcessManagerInterface;
}
```

`QueryRequest` is final readonly and contains `connection`, `originalSql`, `sql`,
`params`, and `kind`. Add `withSqlAndParams()` returning a new request while
preserving connection, original SQL, and kind.

`ConnectionInitializationFailedEvent` contains safe metadata only: connection
name, canonical driver name, host, database, and original error. Do not include
username, password, DSN, or the failed connection object. If a failure-event
listener throws, `ConnectionInitializationException::getPrevious()` must still be
the initializer error; preserve the notification error separately rather than
masking the root cause.

`CredentialNotFoundException` extends `ConnectionException`; keep the existing
final `AuthenticationException` unchanged. `ConnectionInitializationException`
extends `ConnectionException`, retains the initializer error as `previous`, and
adds `public readonly ?\Throwable $notificationError`.
`ExtensionConfigurationException` is a concrete base for generic build failures;
registration exceptions extend it.

### Identifier rules

Use internal `Support\ExtensionIdentifier::normalize()` from builder, driver
registry, and format registry. Normalize with `strtolower(trim($id))`; reject
empty output, control characters, and internal whitespace. Do not expose this
helper in the stable API allowlist.

### Tests

- Enum and string input both normalize to the same stored driver string.
- A third-party value such as `cockroach` is accepted.
- Invalid identifiers fail before registry mutation.
- Query request wither preserves provenance.
- Sensitive connection values do not appear in new exception/event string output.

### Done when

All new contracts compile without depending on concrete drivers, platforms,
metadata factories, or sessions.

---

## WP-2 — Platform Role Aggregate

### Goal

Reduce the public engine interface from 85 inherited methods while preserving all
built-in behavior.

### Add

- `src/Platform/PlatformRoles.php`
- `src/Platform/ComposedPlatform.php`
- unsupported role adapters only where a test or built-in definition needs them;
  do not create speculative adapters for every role

### Modify

- `src/Contracts/Platform/PlatformInterface.php`
- `src/Contracts/Platform/IntrospectionDialectInterface.php`
- `src/Platform/AbstractPlatform.php`
- `src/Platform/MySQLPlatform.php`
- `src/Platform/MariaDbPlatform.php`
- `src/Platform/PostgreSQLPlatform.php`
- `src/Platform/SqlitePlatform.php`
- `src/Platform/SqlServerPlatform.php`
- all production call sites of old inherited methods
- `tests/Unit/Contracts/Platform/PlatformContractsTest.php`
- platform contract and conformance tests

### Implementation shape

`PlatformRoles` is final readonly and stores the five role interfaces. It exposes
`withDdl()`, `withIntrospection()`, `withQueryDialect()`, `withQuoting()`, and
`withTypes()`. Each wither returns a new aggregate.

`ComposedPlatform` stores identity/capability data plus `PlatformRoles` and
forwards only the seven identity methods and five role accessors. It does not
forward role methods. Its constructor receives canonical name, roles, a
`Closure(ConnectionInterface): ServerVersion`, a
`Closure(ServerVersion): CapabilitySet`, optional flavor/default charset/default
collation, and `supportsSchemas`.

`AbstractPlatform` continues to implement:

- `PlatformInterface`;
- `DdlDialectInterface`;
- `IntrospectionDialectInterface`;
- `QueryDialectInterface`;
- `QuotingInterface`;
- `TypeMapperInterface`.

Its role accessors return `$this`. Existing built-in method bodies may remain
where they are.

### Mechanical call-site migration

Use role accessors outside platform implementations:

| Existing use | Replacement |
|---|---|
| metadata SQL methods | `$platform->introspection()->...` |
| DDL rendering | `$platform->ddl()->...` |
| pagination, operators, aggregates, explain, timeout | `$platform->queryDialect()->...` |
| identifier/value/binary conversion | `$platform->quoting()->...` |
| type mapping/catalogs | `$platform->types()->...` |

Primary directories requiring review:

- `src/Metadata/`
- `src/DDL/`
- `src/Query/`
- `src/Execution/ExplainService.php`
- `src/Execution/QueryExecutor.php`
- `src/Security/OperatorValidator.php`
- `src/Connection/PdoConnection.php`
- `src/Export/SqlFormatWriter.php`

Calls inside a built-in platform may continue using `$this->method()`.

### Tests

1. Reflection asserts `PlatformInterface` has exactly 12 methods: seven identity
   methods and five role accessors.
2. Reflection asserts `QueryDialectInterface` owns the specified query methods.
3. Reflection asserts introspection no longer contains explain or timeout methods.
4. Every built-in platform returns itself for each role and passes existing
   conformance tests.
5. `ComposedPlatform` can replace one role without replacing the other four.
6. A fake unsupported role throws `CapabilityNotSupportedException`, not
   `RuntimeException`.

### Done when

No production caller invokes a role method directly on `PlatformInterface`, and
all existing built-in SQL snapshots remain unchanged unless an existing snapshot
was incorrect.

---

## WP-3 — Metadata Inspector Set and Atomic Driver Registry

### Goal

Make metadata and process construction part of canonical driver registration.
Remove all platform-name switches from session assembly.

### Add

- `src/Metadata/MetadataInspectorSet.php`
- `src/Metadata/DefaultMetadataInspectorSetFactory.php`
- `src/Driver/RegisteredDriver.php` marked `@internal`

### Modify

- `src/Driver/DriverRegistry.php`
- `src/Schema/SchemaManagerFactory.php`
- `src/Metadata/ExportSource.php` to consume inspectors supplied from the set
- metadata factory and registry tests

### `MetadataInspectorSet`

Store exactly one adapter for each required interface:

- server;
- database;
- table;
- column;
- index;
- foreign key;
- view;
- routine;
- trigger;
- sequence;
- check constraint;
- user;
- optional privilege.

The set is final readonly with named getters for every role. Add typed withers for
`server`, `foreignKeys`, and `privileges`, because the verification examples use
those replacements. Add other typed withers only when a documented example or
test needs them. Do not add a generic string-based replacement API.

`DefaultMetadataInspectorSetFactory` accepts the engine's existing
`MetadataFactoryInterface` and creates the existing concrete inspectors.

### `DriverRegistry`

Store canonical runtime definitions, not bare drivers. Each runtime entry must
make these available together:

- materialized `DriverInterface`;
- `MetadataInspectorSetFactoryInterface`;
- optional `ProcessManagerFactoryInterface`.

Use internal final readonly `RegisteredDriver` for the materialized driver plus
metadata and process factories. Do not expose mutable arrays.

Required registry behavior:

- `register()` rejects duplicate canonical names;
- `replace()` rejects missing names;
- `registerAlias()` rejects duplicate aliases and aliases colliding with canonical
  names;
- `replaceAlias()` rejects missing aliases;
- alias targets are canonical names only;
- lookup returns the canonical runtime definition;
- error messages name the offending identifier but contain no credentials.

Remove or replace `getByDriver(DatabaseDriver)` with lookup accepting
`string|DatabaseDriver`. Do not require enum conversion for third-party IDs.

### `SchemaManagerFactory`

Replace:

- `metadataFactory(ConnectionInterface)`;
- `forConnection()` constructing its own inspectors;
- `exportSourceForConnection()` constructing another inspector graph.

With helpers that consume an already-created set:

```php
public static function schemaManager(
    MetadataInspectorSet $inspectors,
    ?MetadataCacheInterface $cache,
    ?SchemaEventDispatcherInterface $events,
): SchemaManager;

public static function exportSource(MetadataInspectorSet $inspectors): ExportSource;
```

Apply metadata decorators in builder registration order. Validate that every
decorator returns `MetadataInspectorSet`; exceptions propagate unchanged. No
`match ($connection->getPlatformName())` remains in schema or export assembly.

### Tests

- Default factory creates every required role.
- Two `create()` calls produce independent sets.
- Registry duplicate and replacement policies match builder invariants.
- Alias chains are rejected.
- Schema, export, CSV import, process listing, and privilege security receive the
  same decorated inspector objects.
- A fake third-party driver name reaches metadata creation without core source
  changes.

### Done when

Searching `SchemaManagerFactory` for built-in platform names returns no matches.

---

## WP-4 — Query Interceptor Pipeline and Executor Corrections

### Goal

Create one deterministic transformation path used by every statement operation.

### Add

- `src/Execution/QueryInterceptorPipeline.php`

### Modify

- `src/Execution/QueryExecutor.php`
- `src/Contracts/Execution/QueryExecutorInterface.php`
- `src/Execution/BatchExecutor.php`
- `src/Import/CsvImporter.php`
- `src/Events/BeforeQueryExecuted.php`
- query, batch, import, event, and history tests

### Pipeline algorithm

For each interceptor in registration order:

1. Call `intercept($current)`.
2. Require a `QueryRequest` result.
3. Require the same connection object as the initial request.
4. Require unchanged `originalSql` and `kind`.
5. Require `trim($result->sql) !== ''`.
6. Require parameter keys to be all integers or all strings; reject mixed key
   styles because PDO placeholder semantics become ambiguous.
7. Use the result as the next input.

An interceptor exception propagates unchanged. `OperationCancelledException`
therefore cancels without a database call.

### Executor shape

Refactor duplicated query/execute/DDL logic behind private methods. Preserve
public return types. Intercept exactly once per public operation.

Required semantic kinds:

| Path | Kind |
|---|---|
| `query()` | `Select` |
| `execute()` | `Dml` |
| `executeDdl()` | `Ddl` |
| process kill | `Administrative` |
| typed insert/update/delete | `Dml` |

Add `executeAdministrative()` for administrative commands and
`executeWithTimeout()` for non-row-returning statements. Keep
`queryWithTimeout()` for row-returning statements. All three execution methods
return the existing typed result appropriate to their transport. Timeout methods
use `queryDialect()->wrapWithTimeout()` before interception and return `null`
when the platform cannot provide timeout SQL.

`BatchExecutor` keeps its existing classifier only to choose query versus execute
transport. With timeout enabled:

- row-returning statement -> `queryWithTimeout()`;
- non-row-returning statement -> `executeWithTimeout()`.

`CsvImporter` requires `QueryExecutorInterface` in its constructor, removes its
optional/direct prepared-statement fallback, and uses `execute()` or
`executeWithTimeout()` for every generated `INSERT` statement.

### Event changes

`BeforeQueryExecuted` exposes readonly final SQL, params, and `QueryKind`. Remove
`replaceSql()`. `BeforeDdlExecuted` remains readonly. Both may cancel through the
existing stoppable event base.

Order for every executed statement:

1. dialect timeout wrapping, when requested;
2. interceptor pipeline;
3. before event;
4. cancellation check;
5. database call;
6. history;
7. success/slow event.

Failure history and `QueryFailedEvent` use final SQL and params. Do not log the
untransformed SQL as the executed statement.

### Tests

Implement every query test in section 8 of `03-verification.md`, plus:

- timeout wrapper is not applied twice;
- interceptor runs once under timeout;
- DML timeout uses execute transport;
- mixed positional/named parameter keys are rejected;
- changed connection/original SQL/kind is rejected;
- removed `replaceSql()` is absent by reflection.

### Done when

Every high-level path delegates to `QueryExecutor`, and direct low-level
`ConnectionInterface::query/execute` calls are limited to connection internals,
metadata inspectors, or explicitly documented bootstrap behavior.

---

## WP-5 — Credential Chain and Connection Initialization

### Goal

Make credential misses composable and implement deterministic post-connect setup.

### Add

- `src/Connection/CredentialProviderChain.php`

### Modify

- `src/Contracts/Connection/CredentialProviderInterface.php`
- `src/Connection/ArrayCredentialProvider.php`
- `src/Connection/CallbackCredentialProvider.php`
- `src/Connection/EnvCredentialProvider.php`
- `src/Connection/PdoConnectionFactory.php`
- `src/Connection/ConnectionManager.php`
- `src/Contracts/Connection/ConnectionManagerInterface.php`
- `src/Contracts/Driver/DriverInterface.php`
- all built-in drivers
- `src/Contracts/Events/ConnectionEventDispatcherInterface.php`
- `src/Events/ConnectionEventDispatcher.php`
- `src/Events/ConnectionOpenedEvent.php`
- credential, connection, driver, and event tests

### Credential behavior

Change `resolve()` to `?Credential` everywhere.

- Array provider returns `null` for absent key.
- Callback provider closure returns `?Credential`; exceptions propagate.
- Environment provider returns `null` only when both variables are absent.
- A credential containing `null` username, `null` password, or both is still a
  successful value when a provider explicitly returns it.
- Chain rejects an empty provider list and returns first non-null value.
- Factory throws `CredentialNotFoundException` only after the configured provider
  returns `null`.

### Connection lifecycle ownership

Move before/open/failure lifecycle ownership to `SQLCraftFactory::session()` so
initializers can occur before the opened event.

Change built-in driver `connect()` signatures to accept an optional connection
name and pass it to `PdoConnectionFactory`. `PdoConnectionFactory` creates the PDO
connection and injects the connection event dispatcher into `PdoConnection` for
transaction/close events, but it no longer emits before/open/failure events.

`ConnectionOpenedEvent` includes the live `ConnectionInterface` plus safe metadata.
It fires after manager registration. `ConnectionInitializationFailedEvent` does
not include the connection.

### Initializer failure handling

Wrap only initializer and registration failures after a connection exists:

```text
try initializers in order
if initializer fails:
    close new connection exactly once
    dispatch ConnectionInitializationFailedEvent
    throw ConnectionInitializationException(previous: original)
```

Add `ConnectionManagerInterface::has(string $name): bool`. `add()` rejects a
duplicate instead of silently overwriting it. The factory uses `has()` as a
preflight so a duplicate label cannot open a connection that must then be
discarded.

Do not catch `OperationCancelledException` from the before-open event as a
connection failure. Do not dispatch opened after any failure.

### Tests

Implement sections 6 and 7 of `03-verification.md`. Also assert:

- custom connection name reaches the connection and all lifecycle events;
- connection failure occurs once at factory level, not once in PDO factory and
  again in SQLCraft factory;
- initializers receive effective parameters after credential replacement;
- failed connection is never inserted into `ConnectionManager`;
- duplicate connection labels fail before driver `connect()` is called.

### Done when

No observer can see `ConnectionOpenedEvent` before all mandatory initialization
has succeeded.

---

## WP-6 — Builder, Event Modes, and Immutable Factory Snapshot

### Goal

Create the only supported composition root and wire every existing seam into
factory-created sessions.

### Add

- `src/SQLCraftBuilder.php`
- `src/Events/CompositeEventDispatcher.php` marked `@internal`; it runs core
  first and external second

### Modify

- `src/SQLCraftFactory.php`
- `tests/Unit/SQLCraftFactoryTest.php`
- add `tests/Unit/SQLCraftBuilderTest.php`

### Builder state

Keep mutable state private. Required groups:

- `array<string, DriverDefinition>`;
- `array<string, string>` aliases;
- writer and reader factory maps;
- one credential provider;
- nullable history and cache adapters;
- ordered initializer list;
- ordered interceptor list;
- ordered metadata decorator list;
- SQLCraft-owned listener registrations;
- nullable external dispatcher.

`defaults()` registers built-in drivers, MariaDB alias, all existing writers, CSV
reader, `EnvCredentialProvider`, null query history, and null metadata cache.
`new SQLCraftBuilder()` starts empty. Do not hide this distinction.

`register*()` rejects duplicates. `replace*()` rejects missing targets. Builder
methods return `$this`. `build()` performs complete validation before creating
any externally visible factory.

A repeated `build()` creates independent arrays, registries, listener providers,
and driver instances. Explicitly supplied cache/history/provider objects remain
shared references because the caller owns their scope. Later builder mutations
must not affect an existing factory.

### Factory construction

Replace the current convenience constructor as the composition root. Use one
public `SQLCraftFactory` constructor marked `@internal` because the
builder is a separate class. Its required arguments are the materialized
`DriverRegistry`, credential provider, effective event dispatcher, connection
event adapter, query history, normalized metadata cache, and immutable arrays of
initializers, interceptors, metadata decorators, writer factories, and reader
factories. Documentation and examples use only the builder. Do not retain the old
nullable constructor arguments as a second registration path.

Factory snapshot contains no mutation methods. Session creation uses only the
snapshot. Use this constructor shape; array PHPDoc supplies element types:

```php
/**
 * @param list<ConnectionInitializerInterface> $initializers
 * @param list<QueryInterceptorInterface> $interceptors
 * @param list<\Closure(MetadataInspectorSet, ConnectionInterface): MetadataInspectorSet> $metadataDecorators
 * @param array<string, \Closure(ConnectionInterface): FormatWriterInterface> $writerFactories
 * @param array<string, \Closure(): FormatReaderInterface> $readerFactories
 */
public function __construct(
    DriverRegistry $drivers,
    CredentialProviderInterface $credentials,
    EventDispatcherInterface $events,
    ConnectionEventDispatcherInterface $connectionEvents,
    ?QueryHistoryInterface $history,
    MetadataCacheInterface $cache,
    array $initializers,
    array $interceptors,
    array $metadataDecorators,
    array $writerFactories,
    array $readerFactories,
) {}
```

### Core versus user events

Always create a private core provider and register cache invalidation when a real
cache is configured.

- Owned mode: build a separate user provider/dispatcher, then dispatch core first
  and user second.
- External mode: dispatch core first, then external.
- Neither configured: use the core dispatcher alone.
- Both configured: throw `ExtensionConfigurationException` at `build()`.

Internal core listeners are not counted as builder user listeners and do not make
external mode invalid. User priority and stoppage never suppress a core listener.

### Assembly requirements

`SQLCraftFactory::session()` must pass the same instances through the graph:

- one `QueryExecutor` to session query, DDL, batch, importer, exporter, CSV import,
  and process control;
- one decorated metadata set to schema, export source, CSV importer, process
  listing, and privilege security;
- one metadata cache to schema and core invalidation listener;
- one event dispatcher to all event adapter wrappers;
- one session format registry to exporter and `DatabaseSession::formats()`;
- configured query history to the executor.

### Tests

Implement sections 3, 10, and 11 of `03-verification.md`. Include a snapshot test
where a builder is built, mutated, built again, and the first factory retains its
original registrations.

### Done when

The seam-liveness test can obtain and exercise every configured adapter from a
factory-created `DatabaseSession`.

---

## WP-7 — Format Factories and Reader Liveness

### Goal

Prevent state leakage and make writer/reader registrations reachable.

### Modify

- `src/Export/FormatRegistry.php`
- `src/Export/Exporter.php`
- `src/SQLCraftFactory.php`
- `src/DatabaseSession.php`
- format/export/import tests

### Registry contract

Construct a session registry from:

- active `ConnectionInterface`;
- immutable writer factory map;
- immutable reader factory map.

Required methods:

- `getWriter(string $format): FormatWriterInterface` — fresh each call;
- `getReader(string $format): FormatReaderInterface` — fresh each call;
- `getSupportedWriteFormats(): array`;
- `getSupportedReadFormats(): array`.

On resolution:

1. normalize requested name;
2. find factory or throw unsupported-format exception;
3. invoke factory;
4. verify returned interface;
5. verify adapter `getFormatName()` equals canonical registration name;
6. return fresh adapter.

A factory returning the wrong type or name is an extension configuration error,
not an unsupported-format error.

`Exporter` stores `FormatRegistry`, not a copied writer instance map. It resolves
inside `export()`.

`DatabaseSession` adds:

```php
public function formats(): FormatRegistry;
public function csvImport(): CsvImporterInterface;
```

Construct `CsvImporter` with the set's column inspector, shared import/export
event adapter, and shared query executor. Do not add global sink registrations. Do
not route arbitrary `FormatReaderInterface` implementations through SQL statement
`Importer`; the reader parses rows and the importer executes SQL. A future generic
row-import service requires its own plan.

### Tests

Implement section 9 of `03-verification.md`. Explicitly test two exports on the
same session and two reader resolutions return distinct objects.

### Done when

No mutable writer or reader instance is stored in builder or factory state.

---

## WP-8 — Schema Return Type and Process Managers

### Goal

Expose current schema capability and make advertised process kill capability live.

### Add

- `src/Execution/MySQLProcessManager.php`
- `src/Execution/MySQLProcessManagerFactory.php`
- `src/Execution/PostgreSQLProcessManager.php`
- `src/Execution/PostgreSQLProcessManagerFactory.php`
- `src/Execution/SqlServerProcessManager.php`
- `src/Execution/SqlServerProcessManagerFactory.php`

Keep the six explicit engine files. Do not replace them with a public generic SQL
template API.

### Modify

- `src/DatabaseSession.php`
- `src/SQLCraftFactory.php`
- built-in `DriverDefinition` registrations
- `src/Contracts/Schema/SchemaManagerInterface.php` only for documentation or
  deprecation; do not add manager methods
- process, capability, schema, and session tests

### Session surface

Change:

```php
public function schema(): SchemaManager;
public function processes(): ProcessManagerInterface;
```

Store process manager as nullable internally. `processes()` checks the active
platform capability and configured manager. If either is absent, throw
`CapabilityNotSupportedException::for(Capability::Kill, platform, version)`.

`ProcessManagerInterface::list()` delegates to the session's shared
`ServerInspectorInterface`. It must not construct another metadata inspector.

`kill()` delegates through the shared `QueryExecutor` administrative path.
Engine SQL:

- MySQL/MariaDB: `KILL <validated-int>`;
- PostgreSQL: `SELECT pg_terminate_backend(?)` with bound integer;
- SQL Server: `KILL <validated-int>`.

Do not claim SQLite process-list or kill support.

### Tests

- `DatabaseSession::schema()` exposes representative methods currently hidden by
  `SchemaManagerInterface`, including tables and process list.
- Built-in platforms advertising `Kill` receive a process factory.
- No platform lacking a process factory advertises `Kill`.
- Invalid IDs (`0`, negative, decimals, signs, whitespace, SQL fragments) fail
  before query execution.
- Valid numeric string is normalized to integer.
- A process factory returning the wrong type fails during session assembly.
- Kill query uses `QueryKind::Administrative` and reaches interception, history,
  before/success/failure events.

### Done when

`tests/Unit/Architecture/SeamLivenessTest.php` proves every advertised `Kill`
capability has a reachable session operation.

---

## WP-9 — Third-Party Driver and Cross-Seam Conformance

### Goal

Prove the public extension seam works without editing core source.

### Add

- `tests/Fixtures/Extension/FakeDriver.php`
- `tests/Fixtures/Extension/FakePlatformRoles.php` or small role fixtures
- `tests/Fixtures/Extension/FakeMetadataInspectorSetFactory.php`
- `tests/Contract/Extension/ThirdPartyDriverConformanceTest.php`
- `tests/Unit/Architecture/ExtensionSeamLivenessTest.php`

### Fixture rules

The fake driver uses a name absent from `DatabaseDriver`, such as `fixturedb`.
Its test registration must use public builder methods only. Do not reach into
builder/factory private state or edit a core switch.

The fixture proves:

1. string driver identifier survives `ConnectionParameters`;
2. builder registers canonical definition and alias;
3. driver factory receives the effective connection event dispatcher;
4. composed platform roles function;
5. metadata set builds once per connection;
6. metadata decorator replacement is observed by schema and export;
7. custom writer and reader resolve from the session;
8. query interceptor transforms executed SQL;
9. query history records final SQL;
10. connection initializer runs before opened event;
11. no process manager means no `Kill` capability and typed failure;
12. no production source file contains `fixturedb`.

### Architecture liveness

Expand or replace brittle source-string checks with behavior where practical.
Keep source scans only for negative architecture rules such as:

- no platform-name metadata switch;
- no `BeforeQueryExecuted::replaceSql()`;
- no service-provider/bundle classes;
- no extension registration methods on immutable factory/session classes.

### Done when

A third-party fixture completes connection, schema, query, and export flows using
only documented public contracts.

---

## WP-10 — Stable Surface, Documentation, and Release Gates

### Goal

Document only proven seams and prevent accidental API drift.

### Add

- `tests/Fixtures/stable-api.php`
- `tests/Unit/Architecture/StableApiTest.php`
- extension-author guide under `docs/advanced/` or `docs/extensions/`
- Adminer migration guide driven by the 79-hook matrix

### Stable allowlist

List intentional public classes/interfaces and exact public methods. Include every
transitive type appearing in their signatures. At minimum include:

- builder, factory, and session caller methods, including CSV import and format
  resolution;
- driver definition and driver contracts;
- platform aggregate and role interfaces;
- metadata set and factory contract;
- credential, initializer, query interceptor/request/kind;
- format, sink, and source contracts;
- history and cache contracts;
- process manager contracts;
- documented events.

Do not automatically include every class under `Contracts`, `DTO`, `Events`, or
`ValueObjects`. Concrete defaults stay internal unless users must construct them.

### Documentation examples

Provide one minimal example each for:

- registering a third-party driver definition;
- replacing one platform role;
- decorating one metadata inspector;
- credential provider chain;
- connection initializer;
- ordered query interceptor;
- writer and reader factory;
- owned event listeners;
- external dispatcher mode;
- query history and metadata cache;
- process listing and kill capability check.

Adminer migration examples must state capability equivalence, not API
compatibility. Do not show Adminer hook names as SQLCraft methods.

### Required full gate

Run from `sqlcraft/`:

```bash
composer validate --strict
composer test
composer test:contract
composer test:golden
composer stan
composer psalm
composer cs
composer deptrac
composer rector
```

Run integration tests supported by the local environment. Record unavailable
engines as environment limitations, not passing results.

Mutation testing remains a release gate only if the repository continues to
enforce it. Do not weaken the threshold merely to finish this work.

### Done when

All gates in `03-verification.md` pass and extension documentation uses only
allowlisted public APIs.

## 6. File Ownership Summary

Use this map to avoid placing contracts in concrete modules.

| Concern | Public contract/value | Concrete/default |
|---|---|---|
| Builder/factory/session | `src/SQLCraftBuilder.php`, existing root classes | same composition root |
| Driver registration | `Contracts/Driver`, `DriverDefinition` | `DriverRegistry`, built-in registrations |
| Platform roles | `Contracts/Platform` | `PlatformRoles`, `ComposedPlatform`, built-ins |
| Metadata set | `Contracts/Metadata` | `Metadata/MetadataInspectorSet*` |
| Credentials/init | `Contracts/Connection` | `Connection/*` |
| Query interception | `Contracts/Execution`, `QueryRequest`, `QueryKind` | `Execution/QueryInterceptorPipeline` |
| Formats | existing export/import contracts | `Export/FormatRegistry` |
| Process control | `Contracts/Execution` | engine process managers under `Execution` |
| Events | PSR-14 plus existing event classes | simple/composite dispatchers under `Events` |

Update `deptrac.yaml` only when a new dependency is intentional. Prefer placing
new concrete classes in existing layers rather than creating new top-level
modules.

## 7. Required Test Strategy per Work Package

For each package:

1. Add or update focused unit tests before broad integration tests.
2. Run the narrow test file.
3. Run the complete unit suite.
4. Run PHPStan and Psalm after public signature changes.
5. Run Deptrac after namespace/dependency changes.
6. Run contract/golden suites after platform or metadata changes.
7. Run `git diff --check` before commit.

Do not accept an interface-only package as complete. Every seam needs a
factory-created session behavior test by WP-9.

## 8. Stop Conditions for the Implementing Agent

Stop and report instead of inventing behavior when:

- vendored Adminer no longer yields the exact 79-hook baseline;
- a requested change requires restoring a rejected plugin/bundle abstraction;
- a platform advertises a capability with no executable service and no explicit
  removal decision in this document;
- a new format/source/sink dependency appears necessary;
- a public signature would expose an internal concrete type absent from the stable
  allowlist;
- integration requires credentials or infrastructure unavailable locally;
- source behavior contradicts a fixed choice above.

Do not stop for ordinary compilation failures caused by the current work package;
fix them within that package.

## 9. Final Completion Checklist

The extension system is complete only when all answers are yes:

- [ ] Does every applicable Adminer hook have a disposition in the 79-hook matrix?
- [ ] Can a third-party string driver identifier create a complete session?
- [ ] Can that driver supply platform roles and metadata without a core switch?
- [ ] Are schema and export using the same decorated metadata set?
- [ ] Are credentials, history, cache, initializers, interceptors, formats, and
      events reachable through the builder-created session?
- [ ] Are registration order and duplicate/replacement semantics deterministic?
- [ ] Are reader and writer instances fresh at operation resolution?
- [ ] Is SQL mutation absent from PSR-14 events?
- [ ] Does every executed statement pass through interception exactly once?
- [ ] Does opened event occur only after successful initialization?
- [ ] Does every advertised `Kill` capability have a live process manager?
- [ ] Does the stable API allowlist contain only tested, documented seams?
- [ ] Do all static, unit, contract, golden, and available integration gates pass?

A class existing in `src/` is not proof. A public builder registration exercised
through a real `DatabaseSession` is proof.

## 10. Copy-Paste Prompt for a Lesser Implementation Model

Use this prompt one work package at a time:

```text
Implement WP-N from
sqlcraft/docs/other/plans/extensions/revised/04-implementation-handoff.md.

Read first:
- 00-plugin-system-adr.md
- WP-N in 04-implementation-handoff.md
- the matching acceptance section in 03-verification.md

Rules:
- Implement only WP-N.
- Do not add ServiceProviderInterface, ExtensionBundle, auto-discovery, generic
  platform decorators, schema visibility filters, regex SQL security, or new
  formats.
- Follow exact fixed choices in section 3.
- Update tests with the code.
- Run the narrow tests, unit suite, PHPStan, Psalm, and Deptrac as applicable.
- Do not weaken tests or static-analysis configuration.
- Report changed files, commands run, results, and any stop condition.
```
