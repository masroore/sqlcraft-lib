# Built-In Extensions Catalog

> **Status:** PLAN ONLY  
> **Phase:** 2 (Convenience — after foundation and default implementations)  
> **Namespace:** `SQLCraft\Extension\`  
> **Adminer plugin equivalents:** `sql-log.php`, `timeout.php`, `backward-keys.php`, `database-hide.php`, etc.

---

## 1. Overview

SQLCraft ships a set of ready-to-use extension implementations in the `SQLCraft\Extension\` namespace. These cover the most common extension patterns and serve as reference implementations for third-party extension authors.

All built-in extensions use exclusively the three mechanisms from `17-plugin-system.md §2`:
- PSR-14 event listeners
- DI-swappable service implementations
- Explicit registration via `FormatRegistry` / `DriverRegistry`

---

## 2. `QueryLogger` — PSR-3 Query Logging

**Adminer equivalent:** `sql-log.php`  
**Mechanism:** PSR-14 event listener (Mechanism 1)

### File

`src/Extension/QueryLogger.php`

### Specification

```php
<?php

declare(strict_types=1);

namespace SQLCraft\Extension;

use Psr\Log\LoggerInterface;
use SQLCraft\Events\AfterQueryExecuted;
use SQLCraft\Events\QueryFailedEvent;
use SQLCraft\Events\SlowQueryDetectedEvent;

/**
 * Logs query execution via a PSR-3 logger.
 *
 * Register as a listener:
 *
 *   $provider->listen(AfterQueryExecuted::class, $logger->onQueryExecuted(...));
 *   $provider->listen(QueryFailedEvent::class, $logger->onQueryFailed(...));
 *   $provider->listen(SlowQueryDetectedEvent::class, $logger->onSlowQuery(...));
 *
 * @api
 */
final class QueryLogger
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly bool $includeParams = false,
    ) {}

    public function onQueryExecuted(AfterQueryExecuted $event): void
    {
        $context = [
            'sql'        => $event->sql,
            'elapsed_ms' => $event->elapsedMs,
            'database'   => $event->connection->getCurrentDatabase(),
        ];
        if ($this->includeParams) {
            $context['params'] = $event->params;
        }
        $this->logger->debug('Query executed.', $context);
    }

    public function onQueryFailed(QueryFailedEvent $event): void
    {
        $this->logger->error('Query failed: ' . $event->exception->getMessage(), [
            'sql'      => $event->sql,
            'database' => $event->connection->getCurrentDatabase(),
        ]);
    }

    public function onSlowQuery(SlowQueryDetectedEvent $event): void
    {
        $this->logger->warning(sprintf('Slow query: %.1f ms', $event->elapsedMs), [
            'sql'        => $event->sql,
            'elapsed_ms' => $event->elapsedMs,
        ]);
    }
}
```

### Registration

```php
// Via ExtensionBundle
final class LoggingExtension extends ExtensionBundle
{
    public function __construct(private readonly LoggerInterface $logger) {}

    #[\Override]
    protected function registerListeners(ListenableProviderInterface $listeners): void
    {
        $ql = new QueryLogger($this->logger, includeParams: false);
        $listeners->listen(AfterQueryExecuted::class, $ql->onQueryExecuted(...));
        $listeners->listen(QueryFailedEvent::class, $ql->onQueryFailed(...));
        $listeners->listen(SlowQueryDetectedEvent::class, $ql->onSlowQuery(...));
    }
}
```

---

## 3. `ReadOnlyGuard` — Veto All Write Operations

**Adminer equivalent:** No direct equivalent; Adminer has no read-only mode at the plugin level.  
**Mechanism:** PSR-14 interception event (Mechanism 1) + `BeforeQueryExecuted`

### File

`src/Extension/ReadOnlyGuard.php`

### Specification

```php
<?php

declare(strict_types=1);

namespace SQLCraft\Extension;

use SQLCraft\Events\BeforeQueryExecuted;
use SQLCraft\Events\BeforeSchemaChange;

/**
 * Vetoes all write operations (DML and DDL) by cancelling before-execute events.
 *
 * Use for read-only connection profiles, analytics sessions,
 * or enforcing read-only replicas at the application level.
 *
 * @api
 */
final class ReadOnlyGuard
{
    private const WRITE_PATTERN = '/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|DROP|CREATE|ALTER|RENAME|GRANT|REVOKE|CALL|EXECUTE)\b/i';

    public function onBeforeQuery(BeforeQueryExecuted $event): void
    {
        if (preg_match(self::WRITE_PATTERN, $event->getSql())) {
            $event->cancel('Read-only mode: write operations are not permitted.');
        }
    }

    public function onBeforeSchemaChange(BeforeSchemaChange $event): void
    {
        $event->cancel('Read-only mode: schema changes are not permitted.');
    }
}
```

### Registration

```php
final class ReadOnlyExtension extends ExtensionBundle
{
    #[\Override]
    protected function registerListeners(ListenableProviderInterface $listeners): void
    {
        $guard = new ReadOnlyGuard;
        $listeners->listen(BeforeQueryExecuted::class, $guard->onBeforeQuery(...), priority: 100);
        $listeners->listen(BeforeSchemaChange::class, $guard->onBeforeSchemaChange(...), priority: 100);
    }
}
```

**Design note:** Priority `100` is used so the `ReadOnlyGuard` runs before other listeners. The priority band conventions should be documented in `07-stability-annotations.md`.

---

## 4. `SlowQueryDetector` — Threshold-Based Slow Query Warning

**Adminer equivalent:** `timeout.php` (limits query runtime)  
**Mechanism:** PSR-14 event listener + `SlowQueryDetectedEvent` already exists  

**Note:** The `SlowQueryDetectedEvent` is already fired by `QueryExecutor`. `SlowQueryDetector` is an event listener that **receives** those events and dispatches a user-defined callback. No new events needed.

### File

`src/Extension/SlowQueryDetector.php`

```php
<?php

declare(strict_types=1);

namespace SQLCraft\Extension;

use SQLCraft\Events\SlowQueryDetectedEvent;

/**
 * Fires a callback when a query exceeds a configured time threshold.
 *
 * The SlowQueryDetectedEvent is already emitted by QueryExecutor;
 * this class provides a simple listener wrapper for common use cases.
 *
 * @api
 */
final class SlowQueryDetector
{
    /** @var callable(SlowQueryDetectedEvent): void */
    private readonly \Closure $callback;

    /**
     * @param float    $thresholdMs  Alert only for queries over this threshold in milliseconds.
     * @param callable $callback     Called with the SlowQueryDetectedEvent when threshold is exceeded.
     */
    public function __construct(
        private readonly float $thresholdMs,
        callable $callback,
    ) {
        $this->callback = \Closure::fromCallable($callback);
    }

    public function onSlowQuery(SlowQueryDetectedEvent $event): void
    {
        if ($event->elapsedMs >= $this->thresholdMs) {
            ($this->callback)($event);
        }
    }
}
```

---

## 5. `TenantScopingInterceptor` — Automatic Tenant Isolation

**Adminer equivalent:** No equivalent (multi-tenancy is out of Adminer's scope).  
**Mechanism:** PSR-14 interception event (Mechanism 1), `BeforeQueryExecuted::replaceSql()`

### File

`src/Extension/TenantScopingInterceptor.php`

```php
<?php

declare(strict_types=1);

namespace SQLCraft\Extension;

use SQLCraft\Events\BeforeQueryExecuted;

/**
 * Rewrites SELECT queries to add a tenant_id WHERE clause automatically.
 *
 * WARNING: This is a demonstration-grade implementation. Production-grade
 * SQL rewriting requires a proper SQL parser (e.g., PhpMyAdmin's sql-parser).
 * This implementation uses simple string injection and is NOT safe for
 * complex queries, subqueries, or JOINs without careful validation.
 *
 * For production use, replace the rewrite logic with a proper parser.
 *
 * @api
 */
final class TenantScopingInterceptor
{
    public function __construct(
        private readonly string $tenantIdColumn,
        private readonly string|\Closure $tenantId,
    ) {}

    public function onBeforeQuery(BeforeQueryExecuted $event): void
    {
        if (strtoupper($event->queryType) !== 'SELECT') {
            return;
        }

        $tenantId = $this->tenantId instanceof \Closure
            ? ($this->tenantId)()
            : $this->tenantId;

        // Naive approach — for illustration only.
        // Replace with a SQL AST rewriter for production use.
        $sql = $event->getSql();
        $params = $event->getParams();

        if (stripos($sql, 'WHERE') !== false) {
            $sql = preg_replace('/\bWHERE\b/i', "WHERE {$this->tenantIdColumn} = ? AND ", $sql, 1);
        } else {
            $sql .= " WHERE {$this->tenantIdColumn} = ?";
        }

        array_unshift($params, $tenantId);
        $event->replaceSql($sql, $params);
    }
}
```

---

## 6. `BackwardKeysExtension` — Referencing Foreign Keys

**Adminer equivalent:** `backward-keys.php`

Adminer's `backward-keys.php` shows which other tables have foreign keys pointing **to** the current table. In SQLCraft, this is modeled via `ForeignKeyInspectorInterface::getReferencingKeys()` (a DI-swappable service, Mechanism 2).

No new extension class is needed — the mechanism is already correct. What is needed:

1. Verify `ForeignKeyInspectorInterface` has a `getReferencingKeys(string $table): array` method.
2. Verify all platform implementations (MySQL, PostgreSQL, SQLite, SQL Server) implement backward key introspection.
3. Document that consumers get this by default — it's not an optional extension to enable.

**Action required:** Audit `src/Contracts/Metadata/ForeignKeyInspectorInterface.php` and all platform implementations.

---

## 7. `ConnectionTracer` — Observe All Connection Events

No Adminer equivalent. Provides comprehensive PSR-14 listener for all connection lifecycle events.

```php
// src/Extension/ConnectionTracer.php
final class ConnectionTracer
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function onOpened(ConnectionOpenedEvent $event): void
    {
        $this->logger->info('Connection opened.', [
            'name' => $event->name, 'driver' => $event->driver, 'elapsed_ms' => $event->elapsedMs,
        ]);
    }

    public function onFailed(ConnectionFailedEvent $event): void
    {
        $this->logger->error('Connection failed: ' . $event->error->getMessage(), [
            'name' => $event->name, 'driver' => $event->driver,
        ]);
    }

    public function onClosed(ConnectionClosedEvent $event): void
    {
        $this->logger->info('Connection closed.', ['name' => $event->name]);
    }

    public function onTransactionCommitted(TransactionCommittedEvent $event): void
    {
        $this->logger->debug('Transaction committed.', ['savepoint' => $event->savepoint]);
    }

    public function onTransactionRolledBack(TransactionRolledBackEvent $event): void
    {
        $this->logger->warning('Transaction rolled back.', ['reason' => $event->reason]);
    }
}
```

---

## 8. Testing Requirements

| Extension | Test | Type |
|---|---|---|
| `QueryLogger` | Emits debug/error/warning log at correct level | Unit (mock logger) |
| `ReadOnlyGuard` | Cancels INSERT/UPDATE/DELETE/DDL | Unit |
| `ReadOnlyGuard` | Allows SELECT through | Unit |
| `SlowQueryDetector` | Fires callback above threshold | Unit |
| `SlowQueryDetector` | Suppresses callback below threshold | Unit |
| `TenantScopingInterceptor` | Rewrites SELECT with WHERE | Unit |
| `TenantScopingInterceptor` | Appends to existing WHERE clause | Unit |
| `ConnectionTracer` | Logs opened/failed/closed events | Unit (mock logger) |
| `ReadOnlyGuard` end-to-end | Write throws via `QueryExecutor` | Integration |
| `QueryLogger` end-to-end | Query log appears after `$session->execute()` | Integration |

---

## 9. File Summary

| File | New/Modified |
|---|---|
| `src/Extension/QueryLogger.php` | 🆕 New |
| `src/Extension/ReadOnlyGuard.php` | 🆕 New |
| `src/Extension/SlowQueryDetector.php` | 🆕 New |
| `src/Extension/TenantScopingInterceptor.php` | 🆕 New |
| `src/Extension/ConnectionTracer.php` | 🆕 New |
| `tests/Extension/QueryLoggerTest.php` | 🆕 New |
| `tests/Extension/ReadOnlyGuardTest.php` | 🆕 New |
| `tests/Extension/SlowQueryDetectorTest.php` | 🆕 New |
| `tests/Extension/TenantScopingInterceptorTest.php` | 🆕 New |
| `tests/Extension/ConnectionTracerTest.php` | 🆕 New |
