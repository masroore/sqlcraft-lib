# 16 — Event System

> **Status:** Design draft
> **Scope:** `SQLCraft\Events` namespace — PSR-14 integration, domain event catalog, observability vs interception distinction, listener registration, ordering/priority, cancellable events, plugin/extension forward reference, full event table
> **Depends on:** 05-domain-model.md (exception hierarchy, DTOs), 10-connection-layer.md (ConnectionInterface), 12-query-engine.md (QueryExecutor, TransactionManager), 11-schema-services.md (SchemaManager)
> **Namespace root:** `SQLCraft\Events`
> **Forward reference:** 17-plugin-system.md (extension/interception points described here)

---

## 1. Why an Event System

Adminer uses a plugin system based on `__call` magic method delegation through a chain of plugin objects:

```php
// Adminer plugin mechanism — do NOT copy
class Adminer {
    function __call($method, $args) {
        foreach ($this->plugins as $plugin) {
            if (method_exists($plugin, $method)) {
                return call_user_func_array([$plugin, $method], $args);
            }
        }
        return call_user_func_array(['parent', $method], $args);
    }
}
```

Problems with this approach for SQLCraft:

1. **Hardcoded extension points.** Every hookable method must be listed in `Adminer` or in the plugin interface. Adding a new extension point requires touching the core.
2. **No cross-cutting concerns.** Logging every query requires a plugin that intercepts `selectQuery`, `query`, `editRows`, etc. separately. There is no single "query executed" event.
3. **Magic delegation.** `__call` is invisible to IDEs and static analysers. PHPStan cannot track what `$plugin->query()` returns.
4. **No priority.** Multiple plugins run in registration order with no way to control ordering.
5. **No observability separation.** A logging plugin and a caching plugin and a rate-limiting plugin all use the same mechanism, even though they have fundamentally different roles.

SQLCraft separates concerns:
- **Observability events** (logging, metrics, audit trails) — fired after the fact; no return value; cannot modify behaviour.
- **Interception / extension events** — fired before or during an operation; a listener can modify the operation's input or veto it.

Both integrate with PSR-14 so consumers use the same event dispatcher they already have (Symfony EventDispatcher, Laravel's, or any PSR-14 implementation).

---

## 2. PSR-14 Integration

SQLCraft does not ship its own event dispatcher. It integrates with `Psr\EventDispatcher\EventDispatcherInterface`:

```php
namespace SQLCraft\Contracts\Events;

use Psr\EventDispatcher\EventDispatcherInterface;

interface EventDispatcherAwareInterface
{
    public function setEventDispatcher(EventDispatcherInterface $dispatcher): void;
}
```

All SQLCraft services that emit events accept an optional `EventDispatcherInterface` via constructor injection. When no dispatcher is injected, events are simply not fired — zero overhead in environments that do not need observability.

```php
// Wiring in a DI container (Symfony example)
$executor = new QueryExecutor(
    connection:  $conn,
    dispatcher:  $container->get(EventDispatcherInterface::class),
    history:     $container->get(QueryHistoryInterface::class),
);
```

**Minimal built-in dispatcher:** For consumers not using a full framework, SQLCraft ships `SQLCraft\Events\SimpleEventDispatcher` — a minimal PSR-14-compliant dispatcher that supports listener registration by event class name with optional priority. It has no external dependencies.

```php
namespace SQLCraft\Events;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;

final class SimpleEventDispatcher implements EventDispatcherInterface
{
    public function __construct(
        private readonly ListenerProviderInterface $listenerProvider,
    ) {}

    public function dispatch(object $event): object
    {
        foreach ($this->listenerProvider->getListenersForEvent($event) as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }
            $listener($event);
        }
        return $event;
    }
}
```

---

## 3. Event Object Design

All SQLCraft events are `final readonly` classes. They are immutable once constructed; a listener cannot mutate the event payload of an observability event.

```php
namespace SQLCraft\Events;

// Base marker — all SQLCraft events implement this
interface SQLCraftEventInterface {}

// Observability events: readonly, fire-and-forget
abstract readonly class ObservabilityEvent implements SQLCraftEventInterface {}

// Interception events: mutable state for listeners that modify behaviour
// Implements PSR-14 StoppableEventInterface for veto/cancellation
abstract class InterceptionEvent implements SQLCraftEventInterface, \Psr\EventDispatcher\StoppableEventInterface
{
    private bool $stopped = false;
    private bool $cancelled = false;

    public function stopPropagation(): void { $this->stopped = true; }
    public function isPropagationStopped(): bool { return $this->stopped; }

    /** Cancel the operation that triggered this event. */
    public function cancel(string $reason = ''): void
    {
        $this->stopped   = true;
        $this->cancelled = true;
        $this->cancelReason = $reason;
    }

    public function isCancelled(): bool { return $this->cancelled; }
    public string $cancelReason = '';
}
```

**Why readonly for observability:** Observability events carry a snapshot of what happened. A logging listener cannot rewrite history. `final readonly` enforces this at the PHP type level.

**Why non-readonly for interception:** Interception events need mutable cancellation state. The `cancel()` method sets a flag that the emitting service checks after `dispatch()`.

---

## 4. Observability vs Interception — The Distinction

### 4.1 Observability Events

Fired after an operation completes. Used for:
- Logging queries and their timing.
- Collecting metrics (query count, slow query rate, error rate).
- Audit trails (who executed what DDL at what time).
- Debugging (recording query history — see `QueryHistory` in 12-query-engine.md §11).

Listeners for observability events **must not** throw exceptions in production (they should catch internally and log to a side channel). A broken logging listener should never interrupt a database operation.

**Contract:** Observability events are dispatched _after_ the operation. Listeners see the final result but cannot influence it.

### 4.2 Interception / Extension Events

Fired before or during an operation. Used for:
- Vetoing a query (e.g., a read-only mode guard that blocks all INSERT/UPDATE/DELETE).
- Modifying a query before execution (e.g., adding a tenant-scoping WHERE clause automatically).
- Implementing caching layers (intercept SELECT before execution; return cached result instead).
- Schema change approval workflows (block DDL outside a maintenance window).

**Contract:** Interception events are dispatched _before_ the operation. A listener may call `$event->cancel()` to abort. The service checks `$event->isCancelled()` after dispatch and throws `OperationCancelledException` if true.

```php
// Emitter pattern in QueryExecutor (illustrative):
$event = new BeforeQueryExecuted($conn, $sql, $params);
$this->dispatcher->dispatch($event);
if ($event->isCancelled()) {
    throw new OperationCancelledException($event->cancelReason);
}
// ... proceed with execution
```

This is how the plugin/extension system described in 17-plugin-system.md attaches behaviour without modifying the core.

---

## 5. Domain Events — Full Catalog

### 5.1 Connection Events

| Event | Type | Payload | Notes |
|-------|------|---------|-------|
| `ConnectionOpenedEvent` | Observability | `$name`, `$driver`, `$host`, `$database`, `$elapsedMs` | Fired after successful connect |
| `ConnectionClosedEvent` | Observability | `$name`, `$driver` | Fired on `close()` |
| `ConnectionFailedEvent` | Observability | `$name`, `$driver`, `$error` | Fired when connect throws |
| `BeforeConnectionOpened` | Interception | `$name`, `$params` (ConnectionParams) | Can cancel to prevent connection |

```php
final readonly class ConnectionOpenedEvent extends ObservabilityEvent
{
    public function __construct(
        public readonly string  $name,
        public readonly string  $driver,
        public readonly ?string $host,
        public readonly ?string $database,
        public readonly float   $elapsedMs,
    ) {}
}
```

### 5.2 Query Execution Events

| Event | Type | Payload | Notes |
|-------|------|---------|-------|
| `BeforeQueryExecuted` | Interception | `$conn`, `$sql`, `$params`, `$type` (SELECT/DML/DDL) | Can cancel; listener may modify SQL via `$event->replaceSql()` |
| `AfterQueryExecuted` | Observability | `$conn`, `$sql`, `$params`, `$result` (ExecutionResult), `$elapsedMs` | Full timing + row counts |
| `QueryFailedEvent` | Observability | `$conn`, `$sql`, `$params`, `$exception`, `$elapsedMs` | Exception preserved for logging |
| `SlowQueryDetectedEvent` | Observability | `$conn`, `$sql`, `$params`, `$elapsedMs`, `$thresholdMs` | Fired when execution exceeds threshold |
| `BeforeDdlExecuted` | Interception | `$conn`, `$sql`, `$objectName` | Can cancel; used for change-approval workflows |
| `AfterDdlExecuted` | Observability | `$conn`, `$sql`, `$objectName`, `$elapsedMs` | Triggers metadata cache invalidation |

`BeforeQueryExecuted` allows an intercepting listener to replace the SQL via a controlled mutation method:

```php
// Interception event — mutable for SQL replacement
final class BeforeQueryExecuted extends InterceptionEvent
{
    private string $sql;
    private array  $params;

    public function __construct(
        public readonly ConnectionInterface $conn,
        string                              $sql,
        array                               $params,
        public readonly string              $queryType, // 'SELECT' | 'DML' | 'DDL'
    ) {
        $this->sql    = $sql;
        $this->params = $params;
    }

    public function getSql(): string   { return $this->sql; }
    public function getParams(): array { return $this->params; }

    /** Replace the SQL. Only identifiers/structure — never used to inject values. */
    public function replaceSql(string $sql, array $params): void
    {
        $this->sql    = $sql;
        $this->params = $params;
    }
}
```

**Use case for `replaceSql`:** A multi-tenant extension adds `AND tenant_id = ?` to every SELECT automatically. The extension registers a `BeforeQueryExecuted` listener, parses the query type, and appends the tenant clause. This is the plugin/extension pattern (17-plugin-system.md).

### 5.3 Transaction Events

| Event | Type | Payload | Notes |
|-------|------|---------|-------|
| `TransactionBeganEvent` | Observability | `$conn`, `$isolationLevel`, `$savepoint` (null if top-level) | |
| `TransactionCommittedEvent` | Observability | `$conn`, `$savepoint`, `$elapsedMs` | |
| `TransactionRolledBackEvent` | Observability | `$conn`, `$savepoint`, `$reason` | |
| `BeforeTransactionBegan` | Interception | `$conn`, `$isolationLevel` | Can cancel (e.g., read-only mode) |

### 5.4 Schema / Metadata Events

| Event | Type | Payload | Notes |
|-------|------|---------|-------|
| `SchemaChangedEvent` | Observability | `$conn`, `$objectType`, `$objectName`, `$operation` (CREATE/ALTER/DROP) | Triggers metadata cache invalidation |
| `MetadataFetchedEvent` | Observability | `$conn`, `$objectType`, `$objectName`, `$elapsedMs` | For slow-introspection detection |
| `BeforeSchemaChange` | Interception | `$conn`, `$objectType`, `$objectName`, `$operation`, `$sql` | Can cancel; approval workflows |

`SchemaChangedEvent` is the trigger for `MetadataCacheInterface::invalidateTable()` (11-schema-services.md §5). The `SchemaManager` listens for this event and invalidates accordingly. Consumers can also listen to it for their own cache invalidation.

### 5.5 Import / Export Events

| Event | Type | Payload | Notes |
|-------|------|---------|-------|
| `ImportStartedEvent` | Observability | `$conn`, `$source`, `$format`, `$estimatedBytes` | |
| `ImportProgressEvent` | Observability | `$conn`, `$bytesProcessed`, `$statementsExecuted`, `$elapsedMs` | Fired periodically (every N statements) |
| `ImportFinishedEvent` | Observability | `$conn`, `$statementsExecuted`, `$errors`, `$elapsedMs` | |
| `ImportFailedEvent` | Observability | `$conn`, `$exception`, `$lastSql`, `$elapsedMs` | |
| `ExportStartedEvent` | Observability | `$conn`, `$target`, `$format`, `$tables` | |
| `ExportProgressEvent` | Observability | `$conn`, `$tablesExported`, `$rowsExported`, `$elapsedMs` | |
| `ExportFinishedEvent` | Observability | `$conn`, `$tablesExported`, `$rowsExported`, `$elapsedMs` | |

### 5.6 Capability Events

| Event | Type | Payload | Notes |
|-------|------|---------|-------|
| `CapabilityNotSupportedEvent` | Observability | `$capability`, `$platformName`, `$version` | Fired before `CapabilityNotSupportedException` is thrown |

This allows consumers to log or record capability gaps for telemetry without catching exceptions.

### 5.7 Slow Query Detection

The `SlowQueryThreshold` is configured at `QueryExecutor` construction (default: 1000ms, 0 = disabled). After every `AfterQueryExecuted`, the executor compares `$elapsedMs` against the threshold and fires `SlowQueryDetectedEvent` when exceeded. This is equivalent to Adminer's `slowQuery()` mechanism but observability-based rather than connection-method-based.

---

## 6. Listener Registration and DI

### 6.1 With a Framework Dispatcher

In Symfony, listeners are registered via service tags:

```yaml
# services.yaml
App\Listener\QueryAuditListener:
    tags:
        - { name: kernel.event_listener, event: SQLCraft\Events\AfterQueryExecuted, method: onQueryExecuted }
```

In Laravel:

```php
// EventServiceProvider
protected $listen = [
    \SQLCraft\Events\AfterQueryExecuted::class => [
        \App\Listeners\QueryAuditListener::class,
    ],
];
```

SQLCraft emits PSR-14 events; the framework's dispatcher handles routing, priority, and DI.

### 6.2 With `SimpleEventDispatcher`

```php
use SQLCraft\Events\{SimpleEventDispatcher, SimpleListenerProvider};
use SQLCraft\Events\AfterQueryExecuted;

$provider = new SimpleListenerProvider();
$provider->listen(AfterQueryExecuted::class, function (AfterQueryExecuted $event): void {
    $logger->info('Query executed', [
        'sql'     => $event->sql,
        'elapsed' => $event->elapsedMs,
        'rows'    => $event->result->affectedRows,
    ]);
}, priority: 0);

$dispatcher = new SimpleEventDispatcher($provider);
```

`SimpleListenerProvider` is a PSR-14 `ListenerProviderInterface` implementation:

```php
final class SimpleListenerProvider implements \Psr\EventDispatcher\ListenerProviderInterface
{
    /** @var array<class-string, list<array{callable, int}>> */
    private array $listeners = [];

    /** @param class-string $eventClass */
    public function listen(string $eventClass, callable $listener, int $priority = 0): void
    {
        $this->listeners[$eventClass][] = [$listener, $priority];
        usort(
            $this->listeners[$eventClass],
            fn($a, $b) => $b[1] <=> $a[1], // higher priority first
        );
    }

    public function getListenersForEvent(object $event): iterable
    {
        $class = $event::class;
        foreach ($this->listeners[$class] ?? [] as [$listener]) {
            yield $listener;
        }
        // Also check parent class and interfaces for broader subscriptions
        foreach (class_implements($event) as $interface) {
            foreach ($this->listeners[$interface] ?? [] as [$listener]) {
                yield $listener;
            }
        }
    }
}
```

---

## 7. Ordering and Priority

PSR-14 does not specify priority; that is a `ListenerProvider` concern. SQLCraft's `SimpleListenerProvider` supports an integer priority (higher = earlier). The recommended priority bands:

| Band | Range | Use case |
|------|-------|----------|
| Security / guard | 1000 | Read-only mode veto, tenant scoping, approval gates |
| Cache / optimisation | 500 | Short-circuit a query by returning cached result |
| Business logic | 100 | Domain-level transformations |
| Observability | 0 (default) | Logging, metrics, audit — always last |
| Post-processing | -100 | Actions after all other listeners |

Observability listeners should never run at high priority — a slow logging sink should not block a security listener.

---

## 8. Cancellable Events (`StoppableEventInterface`)

PSR-14 defines `StoppableEventInterface`. SQLCraft's `InterceptionEvent` base class implements it. When a listener calls `$event->cancel()`:

1. `isPropagationStopped()` returns `true`.
2. The dispatcher stops calling further listeners (per PSR-14 contract).
3. The emitting service checks `$event->isCancelled()` after `dispatch()`.
4. If cancelled, the service throws `OperationCancelledException` carrying `$event->cancelReason`.

```php
// Read-only mode guard — registered at priority 1000
$provider->listen(BeforeQueryExecuted::class, function (BeforeQueryExecuted $event): void {
    if ($this->readOnlyMode->isEnabled() && $event->queryType !== 'SELECT') {
        $event->cancel('Database is in read-only mode.');
    }
}, priority: 1000);
```

The `OperationCancelledException` is a typed exception (05-domain-model.md exception hierarchy) that the consumer can catch and handle — e.g., returning an HTTP 403 in a web app.

---

## 9. Why Events over Adminer's Plugin System

| Concern | Adminer plugins (`__call`) | SQLCraft events |
|---------|--------------------------|-----------------|
| Discoverability | Must read source to find hookable methods | `SQLCraftEventInterface` implementations are IDE-enumerable |
| Type safety | `__call` returns `mixed`; no parameter types | Each event is a typed class; PHPStan checks listeners |
| Cross-cutting | One plugin handles SELECT, one handles INSERT, one handles EXPORT separately | One `BeforeQueryExecuted` listener covers all query types |
| Priority | Registration order; no control | Integer priority bands |
| PSR compatibility | None | PSR-14; works with any framework's dispatcher |
| Observability / interception split | No distinction | Explicit `ObservabilityEvent` vs `InterceptionEvent` hierarchy |
| No-op cost | Zero (no dispatcher installed) | Nearly zero with framework dispatcher; zero without |
| Test doubles | Must mock entire plugin chain | Mock a single event class or listener |

---

## 10. Consumer-Defined Events

Consumers and third-party extensions can define their own events following the same pattern:

```php
namespace Acme\MySQLCraftExtension\Events;

use SQLCraft\Events\ObservabilityEvent;

// A custom observability event for a backup extension
final readonly class BackupCompletedEvent extends ObservabilityEvent
{
    public function __construct(
        public readonly string $database,
        public readonly string $backupPath,
        public readonly int    $tablesExported,
        public readonly float  $elapsedSeconds,
    ) {}
}
```

These events are dispatched through the same PSR-14 dispatcher as SQLCraft core events. No registration with SQLCraft is needed — the dispatcher handles routing by class name. Consumers subscribe to them the same way they subscribe to SQLCraft core events.

---

## 11. Forward Reference: Plugin / Extension System

The interception events described in §4.2 and §5 are the primitive mechanism on which the plugin/extension system (17-plugin-system.md) is built. Specifically:

- **Query interceptors** register listeners on `BeforeQueryExecuted` at high priority.
- **Schema change guards** register listeners on `BeforeSchemaChange`.
- **Tenant scoping** registers a `BeforeQueryExecuted` listener that appends WHERE clauses.
- **Caching layers** register a `BeforeQueryExecuted` listener that checks a cache and cancels the real query, then registers an `AfterQueryExecuted` listener that populates the cache.

The plugin system document will define conventions for packaging these as reusable, namespaced extensions. The event system itself is intentionally general — it does not know about plugins.

---

## 12. Summary of Key Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| PSR-14 vs custom dispatcher | PSR-14 integration | No lock-in; works with Symfony, Laravel, any framework |
| Built-in dispatcher | `SimpleEventDispatcher` + `SimpleListenerProvider` | Zero-dep fallback; no framework required |
| Observability vs interception split | Separate class hierarchies | Clear contract; `readonly` for observability; mutable for interception |
| Cancellable events | `StoppableEventInterface` + `cancel()` | Standard PSR-14 mechanism; no custom veto API |
| Event immutability | `final readonly` for observability | Listeners cannot rewrite history or corrupt audit trails |
| Interception mutability | Controlled via named methods only (`replaceSql`, `cancel`) | No arbitrary mutation; only intentional extension points |
| Priority | Integer bands in `SimpleListenerProvider` | Clear ordering contract; security > cache > business > logging |
| Consumer events | Extend `ObservabilityEvent` / `InterceptionEvent` | Same mechanism for core and extensions; no registration needed |
| Plugin hook pattern | Event listeners, not `__call` delegation | Type-safe; discoverable; cross-cutting; PSR-14 compliant |
| SlowQueryDetectedEvent threshold | Configurable per `QueryExecutor` instance | Different thresholds for different use cases (OLTP vs reporting) |
