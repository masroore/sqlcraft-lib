# SQLCraft Extension System — Revised Implementation Plan

> **Status:** Plan only — do not implement from partial sections
> **Date:** 2026-07-22
> **Adminer baseline:** 5.5.0, local commit `190a70d`
> **Architecture decision:** `00-plugin-system-adr.md`
> **Parity matrix:** `02-adminer-5.5.0-hook-matrix.md`
> **Acceptance gates:** `03-verification.md`

## 1. Goal

Create a typed, deterministic extension architecture that covers every applicable
Adminer 5.5.0 database-logic capability while remaining a framework-independent
PHP library.

Completion means extension seams are reachable through the public composition
root. An interface or registry that exists only in lower-level constructors does
not count as implemented.

## 2. Corrected Current State

### Reuse; do not reimplement

SQLCraft already contains these planned items:

- `DriverRegistry` and built-in MySQL, PostgreSQL, SQLite, and SQL Server drivers.
- `FormatRegistry`.
- SQL, CSV, TSV, semicolon-CSV, JSON, XML, XLSX, and HTML writers.
- CSV reader.
- Resource, gzip, bzip2, PSR-7, multi-file, and string-buffer sinks.
- File, string, stream, and PSR-7 import sources.
- `InMemoryQueryHistory` and `NullQueryHistory`.
- `InMemoryMetadataCache`, `NullMetadataCache`, and PSR-6/PSR-16 adapters.
- PSR-14-compatible events and SQLCraft's simple provider/dispatcher.
- `BeforeQueryExecuted::replaceSql()`; this will be replaced by the ordered
  interceptor design rather than expanded.
- Metadata inspector contracts and built-in implementations.
- Foreign-key and referencing-key inspection.

The revised plan removes all work that merely recreates these classes.

### Existing but not live through the composition root

| Seam | Current problem |
|---|---|
| Formats | `SQLCraftFactory::session()` creates a hardcoded registry for every session. Consumers cannot add a format to a factory-created session. |
| Query history | Implementations exist, but `SQLCraftFactory` constructs `QueryExecutor` without one. |
| Metadata inspectors | `SchemaManagerFactory` constructs concrete inspectors and selects metadata factories with a built-in platform-name switch. |
| External drivers | Driver registration works, but metadata construction fails for unknown platform names. |
| Events | A consumer can inject a dispatcher, but SQLCraft's default listener provider is local to the constructor and cannot be configured later. |
| Schema manager contract | `DatabaseSession::schema()` returns an interface exposing 2 methods while the concrete class exposes 34 public operations. |
| Process control | `Capability::Kill` is advertised, but no live process-kill operation exists. |
| Post-connect setup | `ConnectionOpenedEvent` carries metadata but no connection. |
| Registry conflicts | Drivers, aliases, writers, and readers silently overwrite duplicate names. |

## 3. Public Composition Model

### 3.1 `SQLCraftBuilder`

Add a mutable bootstrap builder. Builder methods return `$this` for fluent setup.
Calling `build()` validates and freezes an immutable configuration.

Required configuration surface:

```php
final class SQLCraftBuilder
{
    public static function defaults(): self;

    public function registerDriver(DriverDefinition $definition): self;
    public function replaceDriver(DriverDefinition $definition): self;
    public function registerDriverAlias(string $alias, string $target): self;
    public function replaceDriverAlias(string $alias, string $target): self;

    /** @param \Closure(ConnectionInterface): FormatWriterInterface $factory */
    public function registerWriter(string $format, \Closure $factory): self;
    /** @param \Closure(ConnectionInterface): FormatWriterInterface $factory */
    public function replaceWriter(string $format, \Closure $factory): self;

    /** @param \Closure(): FormatReaderInterface $factory */
    public function registerReader(string $format, \Closure $factory): self;
    /** @param \Closure(): FormatReaderInterface $factory */
    public function replaceReader(string $format, \Closure $factory): self;

    public function credentials(CredentialProviderInterface $provider): self;
    public function queryHistory(?QueryHistoryInterface $history): self;
    public function metadataCache(?MetadataCacheInterface $cache): self;

    public function initializeConnection(ConnectionInitializerInterface $initializer): self;
    public function interceptQueries(QueryInterceptorInterface $interceptor): self;

    public function metadataInspectors(MetadataInspectorSetFactoryInterface $factory): self;

    /** @param \Closure(MetadataInspectorSet, ConnectionInterface): MetadataInspectorSet $decorator */
    public function decorateMetadataInspectors(\Closure $decorator): self;

    public function listen(string $eventClass, callable $listener, int $priority = 0): self;
    public function eventDispatcher(EventDispatcherInterface $dispatcher): self;

    public function build(): SQLCraftFactory;
}
```

Exact names may change only if a repository-wide naming convention requires it;
the capabilities and invariants above are fixed.

### 3.2 Builder invariants

- Built-in definitions are installed by `defaults()` before user registrations.
- Canonical driver, alias, reader, and writer identifiers are lowercase and
  non-empty.
- `register*()` throws a typed duplicate-registration exception.
- `replace*()` throws if the target does not already exist.
- Aliases reference canonical driver identifiers, not driver objects.
- Alias targets must exist at `build()` time.
- Builder-level `listen()` and `eventDispatcher()` are mutually exclusive.
- `build()` may be called repeatedly only if it produces independent immutable
  factory snapshots; later builder mutation cannot affect an existing factory.
- `SQLCraftFactory` and `DatabaseSession` expose no late registration methods.

### 3.3 Lifecycle

| Registration | Lifetime |
|---|---|
| Driver definition | Factory lifetime |
| Metadata-inspector set | Fresh per connection |
| Writer/reader adapter | Fresh per export/import session or operation |
| Credential provider | Factory lifetime unless supplied adapter manages its own scope |
| Query history | Explicitly supplied shared adapter; `null` disables history |
| Metadata cache | Explicitly supplied shared adapter; `null` uses no-op behavior |
| Connection initializer | Factory lifetime, invoked for each new connection |
| Query interceptor | Session/factory pipeline, invoked for every applicable statement |
| SQLCraft-owned event listeners | Factory lifetime |

## 4. Driver and Platform Extension Seam

### 4.1 Atomic `DriverDefinition`

A driver package must register all engine-specific construction needed by a
factory-created session in one definition:

```php
final readonly class DriverDefinition
{
    public function __construct(
        public DriverInterface $driver,
        public MetadataInspectorSetFactoryInterface $metadata,
        public ?ProcessManagerFactoryInterface $processes = null,
    ) {}
}
```

The definition identifier comes from `DriverInterface::getName()`. A mismatch
between the registered identifier and platform identity is a build-time error.

This replaces `SchemaManagerFactory::metadataFactory()` platform-name switching.
Adding an engine must not require a core `match` arm.

### 4.2 Platform role composition

Replace the transitive 85-method platform interface with a small aggregate:

```php
interface PlatformInterface
{
    public function getName(): string;
    public function getFlavor(): ?string;
    public function getServerVersion(ConnectionInterface $connection): ServerVersion;
    public function getCapabilitySet(ServerVersion $version): CapabilitySet;
    public function getDefaultCharset(): ?string;
    public function getDefaultCollation(): ?string;

    public function ddl(): DdlDialectInterface;
    public function introspection(): IntrospectionDialectInterface;
    public function queryDialect(): QueryDialectInterface;
    public function quoting(): QuotingInterface;
    public function types(): TypeMapperInterface;
}
```

`QueryDialectInterface` owns operator allowlists, aggregate allowlists,
keywords, and pagination behavior. Existing `PaginationInterface` may be
retained as a parent role if that keeps it independently useful.

Provide:

- An immutable `PlatformRoles` aggregate.
- A final `ComposedPlatform` adapter.
- Withers or constructor replacement for one role at a time.
- Explicit unsupported-role adapters where an engine lacks a role.

Do not provide forwarding decorators covering every role method.

### 4.3 Conformance rules

- Every built-in driver and platform passes the same contract suite.
- An advertised capability must have a reachable implementation.
- Unsupported roles throw typed capability exceptions, not generic runtime errors.
- A platform role cannot advertise syntax that its driver/session graph cannot use.
- Third-party fixtures prove engine registration, connection, metadata, query,
  export, and capability resolution without core source changes.

## 5. Metadata Composition

### 5.1 `MetadataInspectorSet`

Create one immutable per-connection aggregate containing the inspector adapters
used by `SchemaManager` and `ExportSource`:

- `ServerInspectorInterface`
- `DatabaseInspectorInterface`
- `TableInspectorInterface`
- `ColumnInspectorInterface`
- `IndexInspectorInterface`
- `ForeignKeyInspectorInterface`
- `ViewInspectorInterface`
- `RoutineInspectorInterface`
- `TriggerInspectorInterface`
- `SequenceInspectorInterface`
- `CheckConstraintInspectorInterface`
- `UserInspectorInterface`
- optional `PrivilegeInspectorInterface`

```php
interface MetadataInspectorSetFactoryInterface
{
    public function create(ConnectionInterface $connection): MetadataInspectorSet;
}
```

Builder decorators receive the default set and active connection and return a
replacement set. This permits one-role decoration without rebuilding unrelated
inspectors.

### 5.2 Shared consumption

The same decorated set constructs both:

- The session's `SchemaManager`.
- The session's export metadata source.

A metadata extension must not affect schema browsing while export silently uses
an independent hardcoded inspector graph.

### 5.3 Public schema surface

`DatabaseSession::schema()` must not return the current two-method interface.
Choose one of these during implementation, in priority order:

1. Return final `SchemaManager` and stabilize its caller methods while keeping
   inspector customization behind `MetadataInspectorSet`.
2. Split caller-facing schema operations into complete role interfaces and return
   an aggregate implementing them.

Do not make consumers implement a 34-method manager merely to replace one
metadata source.

### 5.4 Visibility filtering

Do not add `SchemaFilterInterface`, `PrefixDatabaseFilter`, or
`TenantSchemaFilter` in this plan. Adminer's `database-hide` is presentation
filtering, not authorization. SQLCraft consumers may filter collections in their
UI layer.

## 6. Credential Resolution

Change the provider contract before v1:

```php
interface CredentialProviderInterface
{
    public function resolve(string $key): ?Credential;
}
```

Rules:

- `null` means “not handled/not found.”
- A provider exception means resolution failed and propagates immediately.
- `CredentialProviderChain` returns the first non-`null` credential.
- If every provider returns `null`, the factory throws
  `CredentialNotFoundException` naming the requested key.
- The chain rejects an empty provider list.
- `EnvCredentialProvider` returns `null` only when neither relevant environment
  variable exists.
- A credential with nullable username or password remains a valid resolved value;
  emptiness is never used as a miss signal.
- Do not expose provider lists or `count()` methods unless a real caller requires
  them.

The chain is SQLCraft-native convenience. It is not described as equivalent to
Adminer's `login-servers.php`, which primarily controls a login-form server list.

## 7. Connection Initialization

Add:

```php
interface ConnectionInitializerInterface
{
    public function initialize(
        ConnectionInterface $connection,
        ConnectionParameters $parameters,
    ): void;
}
```

Lifecycle is fixed:

1. Validate parameters and resolve credentials.
2. Open the low-level connection.
3. Run initializers in builder registration order.
4. If an initializer throws:
   - stop remaining initializers;
   - close the connection;
   - dispatch `ConnectionInitializationFailedEvent` if events are enabled;
   - throw `ConnectionInitializationException` with the original exception as
     `previous`.
5. Add the connection to `ConnectionManager` only after successful initialization.
6. Emit `ConnectionOpenedEvent` only after initialization and registration succeed.

Use initializers for session variables, charset/session mode commands, application
name, statement timeout setup, and other required `afterConnect` behavior.

## 8. Ordered Query Interception

### 8.1 Contract

Replace mutable event SQL rewriting with:

```php
enum QueryKind: string
{
    case Select = 'select';
    case Dml = 'dml';
    case Ddl = 'ddl';
    case Administrative = 'administrative';
}

final readonly class QueryRequest
{
    /** @param array<string|int, mixed> $params */
    public function __construct(
        public ConnectionInterface $connection,
        public string $originalSql,
        public string $sql,
        public array $params,
        public QueryKind $kind,
    ) {}
}

interface QueryInterceptorInterface
{
    public function intercept(QueryRequest $request): QueryRequest;
}
```

### 8.2 Pipeline rules

- Apply interceptors in builder registration order.
- Every interceptor receives the previous interceptor's result.
- Connection and original SQL remain immutable provenance.
- Validate non-empty final SQL and parameter shape after each interceptor.
- Cancellation uses `OperationCancelledException`.
- Run the same pipeline for direct query, DML, DDL, batch, timeout, import, and
  builder-rendered execution paths.
- `BeforeQueryExecuted`/`BeforeDdlExecuted` receive final SQL and may cancel, but
  no longer mutate SQL or parameters.
- Success, failure, history, and slow-query events record the final executed SQL.

No core tenant or read-only interceptor is included. Third-party security-sensitive
interceptors require their own parser-backed design and review.

## 9. Format and Sink Extension

### 9.1 Named factories

Store factories rather than shared adapter instances:

```php
/** @var array<string, \Closure(ConnectionInterface): FormatWriterInterface> */
private array $writerFactories;

/** @var array<string, \Closure(): FormatReaderInterface> */
private array $readerFactories;
```

Rules:

- Create a fresh writer for each export operation or, at minimum, each session.
- Create a fresh reader for each import operation.
- Verify factory output implements the required interface.
- Verify returned `getFormatName()` matches the registered canonical name.
- State-bearing writers never leak state between exports or connections.
- `SqlFormatWriter` receives the active connection from its factory.

### 9.2 Caller-owned outputs

Sinks remain operation arguments, not global registrations. The caller chooses
file path, compression, stream, memory buffer, or multi-file behavior at export
time. This covers Adminer's `dumpOutput` capability without creating global output
state.

### 9.3 Removed work

Do not plan new JSON/XML/string implementations already present. PHP writer, ZIP
sink, URL source, or other formats require separate feature plans with their own
security, memory, dependency, and streaming requirements.

## 10. Events

### SQLCraft-owned mode

Calling builder `listen()` stores registrations. `build()` creates
`SimpleListenerProvider` and `SimpleEventDispatcher`. Priority and registration
sequence are deterministic inside this mode.

### Consumer-owned mode

Calling `eventDispatcher()` supplies an external PSR-14 dispatcher. The host
framework owns listener registration and ordering. SQLCraft does not mutate or
inspect its listener provider.

### Mutual exclusion

Using both modes is a build-time configuration error. Do not introduce a
`ListenableProviderInterface` to pretend arbitrary PSR-14 providers share a
registration method.

### Runtime dependency

Move `psr/event-dispatcher` to Composer runtime requirements because core public
interfaces and default implementations reference it. Keep `psr/log` optional
unless a shipped source class directly type-hints it.

## 11. Process Control

Adminer exposes `killProcess`; SQLCraft advertises `Capability::Kill` but has no
live operation. Add a connection-scoped manager only if kill remains advertised:

```php
interface ProcessManagerInterface
{
    public function list(): ProcessCollection;
    public function kill(string|int $processId): void;
}
```

An engine definition supplies an optional `ProcessManagerFactoryInterface`.
`DatabaseSession` exposes the manager only through a stable named method. Engines
without the capability throw `CapabilityNotSupportedException` before rendering
or executing SQL.

If this manager is deferred, remove `Capability::Kill` from every capability set
and mark `killProcess` as an explicit parity gap. Do not leave a dead capability.

## 12. Stability Policy

Create an explicit stable-surface manifest covering only intentional interfaces:

- `SQLCraftBuilder`, `SQLCraftFactory`, and `DatabaseSession` caller methods.
- Driver definition and driver/platform role contracts.
- Metadata-inspector set contracts.
- Credential provider and credential value object.
- Connection initializer.
- Query interceptor/request/kind.
- Format reader/writer and sink/source contracts.
- Query history and metadata cache contracts.
- Events explicitly documented as listener targets.
- Every transitive public type appearing in these signatures.

Concrete defaults remain internal unless consumers must construct them directly.
Unfinished seams remain experimental until liveness and conformance tests pass.

Use an API-reflection snapshot or backward-compatibility check. Do not blanket-tag
every class under `Contracts`, `DTO`, `ValueObjects`, or `Events`.

## 13. Implementation Phases

### Phase A — Truth, dependency, and release baseline

- Adopt the ADR and exact 79-hook matrix.
- Add matrix drift validation against vendored Adminer source.
- Reconcile the premature `1.0.0` changelog entry and absent tag.
- Correct runtime Composer dependencies.
- Remove stale plan claims and duplicate implementation work.

**Exit:** Documentation and package metadata agree on release and dependency state.

### Phase B — Engine and metadata seams

- Split platform roles.
- Add `DriverDefinition`.
- Add metadata-inspector set/factory.
- Remove platform-name metadata switching.
- Use one decorated inspector set for schema and export.
- Resolve process-kill capability liveness.
- Add cross-engine conformance fixtures.

**Exit:** A fake third-party engine builds a complete session without editing core.

### Phase C — Composition root

- Add `SQLCraftBuilder` and immutable factory snapshot.
- Add duplicate/replacement validation.
- Move built-in driver and format setup into builder defaults.
- Wire query history, cache, metadata, formats, events, and session services from
  the snapshot.
- Correct the schema manager public return surface.

**Exit:** Every advertised extension seam is reachable through a factory-created
session.

### Phase D — Ordered lifecycle seams

- Change credential miss semantics and add the chain.
- Add connection initializers and failure cleanup.
- Add the ordered query-interceptor chain.
- Remove SQL replacement from PSR-14 events.
- Convert format registrations to fresh named factories.

**Exit:** Ordering, cancellation, error propagation, and adapter lifetimes are
covered end to end.

### Phase E — Stability and author documentation

- Define and enforce the stable-surface manifest.
- Add extension-author examples for each supported seam.
- Add Adminer migration examples driven by the hook matrix.
- Add upgrade notes for pre-v1 contract changes.

**Exit:** A third-party author can build an extension using only documented public
interfaces and examples.

## 14. Explicit Removals from the Original Plan

Remove these proposed components or work items:

- `ServiceProviderInterface`
- `ExtensionBundle`
- `ListenableProviderInterface`
- `AbstractPlatformDecorator`
- `AbstractDriverDecorator`
- `ReadOnlyPlatformDecorator`
- generic capability-add/remove decorator
- `SchemaFilterInterface` and built-in schema filters
- regex `ReadOnlyGuard`
- `TenantScopingInterceptor`
- redundant `SlowQueryDetector` callback wrapper
- unrelated `ConnectionTracer` catalog item
- blanket `@api`/`@internal` tagging sweep
- custom global ban on `method_exists()`
- reimplementation of existing history, cache, format, sink, and source adapters
- speculative PHP/ZIP/URL format work
- arbitrary 80% coverage and test-pyramid percentages
- developer-day estimates unsupported by an executable task breakdown

Query logging remains a documentation example using `QueryHistoryInterface` or
query events. It does not require a core `QueryLogger` class.
