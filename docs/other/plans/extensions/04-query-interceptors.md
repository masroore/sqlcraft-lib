# Query Interception and `CredentialProviderChain`

> **Authoritative replacement:** `docs/other/plans/extensions-revised/04-implementation-handoff.md` and `03-verification.md`. This document is retained for history and is not an active implementation requirement.


> **Status:** SUPERSEDED — historical reference only
> **Phase:** 0 (Foundation — blocking for built-in extensions)
> **Namespace:** `SQLCraft\Extension\`, `SQLCraft\Connection\`

---

## 1. Query Interception — Current State

Query interception via PSR-14 events is **already implemented**. The event chain for any executed query is:

```
QueryExecutor::execute()
    → fires BeforeQueryExecuted (can cancel OR replace SQL)
    → runs PDO
    → fires AfterQueryExecuted (observation only)
    → fires QueryFailedEvent on error (observation only)
    → fires SlowQueryDetectedEvent if over threshold (observation only)
```

`BeforeQueryExecuted` (in `src/Events/BeforeQueryExecuted.php`) already implements:

```php
public function cancel(string $reason = ''): void    // inherited from InterceptionEvent
public function replaceSql(string $sql, array $params): void  // ← rewrite SQL
public function getSql(): string
public function getParams(): array
```

No new interception events are required. The mechanism is complete.

---

## 2. Missing Query-Related Default Implementations

### 2.1 `InMemoryQueryHistory`

**Adminer context:** Adminer's `sql-log.php` plugin writes every executed SQL to a server-side log. SQLCraft models query history via `QueryHistoryInterface` but provides no default implementation.

**File:** `src/Execution/InMemoryQueryHistory.php`

```php
<?php

declare(strict_types=1);

namespace SQLCraft\Execution;

use SQLCraft\Contracts\Execution\QueryHistoryEntry;
use SQLCraft\Contracts\Execution\QueryHistoryInterface;

/**
 * In-memory query history for development and testing.
 *
 * Not suitable for production long-running processes — history grows unbounded.
 * Use a bounded implementation (e.g., a PSR-6 cache backend) for production.
 *
 * @api
 */
final class InMemoryQueryHistory implements QueryHistoryInterface
{
    /** @var array<string, list<QueryHistoryEntry>> */
    private array $entries = [];

    /** @var int Maximum entries per database (0 = unlimited) */
    public function __construct(private readonly int $maxPerDatabase = 0) {}

    #[\Override]
    public function record(QueryHistoryEntry $entry): void
    {
        $db = $entry->database;
        $this->entries[$db][] = $entry;

        if ($this->maxPerDatabase > 0 && count($this->entries[$db]) > $this->maxPerDatabase) {
            array_shift($this->entries[$db]);
        }
    }

    /** @return list<QueryHistoryEntry> */
    #[\Override]
    public function getRecent(string $database, int $limit = 100): array
    {
        $all = $this->entries[$database] ?? [];
        return array_slice(array_reverse($all), 0, $limit);
    }

    #[\Override]
    public function clearDatabase(string $database): void
    {
        unset($this->entries[$database]);
    }

    public function clearAll(): void
    {
        $this->entries = [];
    }

    /** @return list<string> all tracked database names */
    public function getDatabases(): array
    {
        return array_keys($this->entries);
    }
}
```

### 2.2 `NullQueryHistory`

```php
<?php

declare(strict_types=1);

namespace SQLCraft\Execution;

use SQLCraft\Contracts\Execution\QueryHistoryEntry;
use SQLCraft\Contracts\Execution\QueryHistoryInterface;

/**
 * No-op query history. Discards all entries.
 * Use as the default when no history tracking is needed.
 *
 * @api
 */
final class NullQueryHistory implements QueryHistoryInterface
{
    #[\Override]
    public function record(QueryHistoryEntry $entry): void {}

    #[\Override]
    public function getRecent(string $database, int $limit = 100): array { return []; }

    #[\Override]
    public function clearDatabase(string $database): void {}
}
```

---

## 3. `CredentialProviderChain`

### Purpose

Adminer's `login-servers.php` plugin maintains a list of preconfigured servers. Consumers often need to try multiple credential sources in order (e.g., Vault → environment variable → hardcoded fallback). `CredentialProviderChain` implements the **Chain of Responsibility** pattern for `CredentialProviderInterface`.

**File:** `src/Connection/CredentialProviderChain.php`

```php
<?php

declare(strict_types=1);

namespace SQLCraft\Connection;

use SQLCraft\Contracts\Connection\CredentialProviderInterface;
use SQLCraft\ValueObjects\Credential;

/**
 * Tries each provider in order until one resolves the key.
 *
 * Useful for fallback chains:
 *   new CredentialProviderChain([new VaultProvider, new EnvCredentialProvider])
 *
 * Throws if no provider resolves the key (using the last provider's exception).
 *
 * @api
 */
final class CredentialProviderChain implements CredentialProviderInterface
{
    /** @var list<CredentialProviderInterface> */
    private readonly array $providers;

    /** @param iterable<CredentialProviderInterface> $providers */
    public function __construct(iterable $providers)
    {
        $this->providers = [...$providers];

        if ($this->providers === []) {
            throw new \InvalidArgumentException('CredentialProviderChain requires at least one provider.');
        }
    }

    #[\Override]
    public function resolve(string $key): Credential
    {
        $lastException = null;
        foreach ($this->providers as $provider) {
            try {
                return $provider->resolve($key);
            } catch (\Throwable $e) {
                $lastException = $e;
            }
        }

        throw new \RuntimeException(
            sprintf('No credential provider could resolve key "%s".', $key),
            previous: $lastException,
        );
    }
}
```

### Usage

```php
$provider = new CredentialProviderChain([
    new VaultCredentialProvider($vaultClient, 'secret/sqlcraft'),
    new EnvCredentialProvider,    // falls back to SQLCRAFT_USER / SQLCRAFT_PASS env vars
    new ArrayCredentialProvider(['default' => new Credential('root', '')]),
]);

$factory = new SQLCraftFactory(credentials: $provider);
```

---

## 4. `SchemaFilterInterface` — Database/Table Filtering

**Adminer equivalent:** `database-hide.php` (hides databases from the list), `tables-filter.php` (filters table list by name pattern).

This is a **new interface** not yet defined in SQLCraft. It provides a mechanism for filtering which databases and tables are visible to the consumer.

### File

`src/Contracts/Schema/SchemaFilterInterface.php`

```php
<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Schema;

/**
 * Filters the databases and tables visible to the consumer.
 *
 * Implement to hide system databases, restrict to a tenant's own tables,
 * or apply any other visibility policy.
 *
 * @api
 */
interface SchemaFilterInterface
{
    /**
     * Filter the list of visible database names.
     *
     * @param  list<string>  $databases  all databases returned by the engine
     * @return list<string>  databases that should be visible
     */
    public function filterDatabases(array $databases): array;

    /**
     * Filter the list of visible table names within a database.
     *
     * @param  list<string>  $tables     all tables in the database
     * @param  string        $database   the database being inspected
     * @return list<string>  tables that should be visible
     */
    public function filterTables(array $tables, string $database): array;
}
```

### Built-in Implementations

**`PrefixDatabaseFilter`** — hide databases by prefix:

```php
// src/Schema/PrefixDatabaseFilter.php
final class PrefixDatabaseFilter implements SchemaFilterInterface
{
    /** @param list<string> $hiddenPrefixes */
    public function __construct(private readonly array $hiddenPrefixes) {}

    #[\Override]
    public function filterDatabases(array $databases): array
    {
        return array_values(array_filter(
            $databases,
            fn (string $db) => !array_any(
                $this->hiddenPrefixes,
                fn (string $prefix) => str_starts_with($db, $prefix),
            ),
        ));
    }

    #[\Override]
    public function filterTables(array $tables, string $database): array
    {
        return $tables; // no table filtering
    }
}
```

**`TenantSchemaFilter`** — restrict to a single tenant's databases/tables:

```php
// src/Schema/TenantSchemaFilter.php
final class TenantSchemaFilter implements SchemaFilterInterface
{
    public function __construct(private readonly string $tenantId) {}

    #[\Override]
    public function filterDatabases(array $databases): array
    {
        return array_values(array_filter(
            $databases,
            fn (string $db) => str_starts_with($db, $this->tenantId . '_'),
        ));
    }

    #[\Override]
    public function filterTables(array $tables, string $database): array
    {
        return $tables; // tables not filtered; the database scope already limits
    }
}
```

### Integration with `SchemaManager`

`SchemaManager` / `ServerInspectorInterface::getDatabases()` results should be piped through `SchemaFilterInterface` when one is registered. The integration point is in `SchemaManagerFactory` or the concrete `SchemaManager::getDatabases()` implementation — whichever resolves the database list.

**Wiring:**

```php
// In SchemaManagerFactory or DatabaseSession setup:
$schema = SchemaManagerFactory::forConnection($connection, $cache, $schemaEvents, $filter);
```

---

## 5. Testing Requirements

| Test | Type |
|---|---|
| `InMemoryQueryHistory` records and returns entries in reverse order | Unit |
| `InMemoryQueryHistory` respects `$maxPerDatabase` bound | Unit |
| `NullQueryHistory` silently discards all entries | Unit |
| `CredentialProviderChain` returns from first successful provider | Unit |
| `CredentialProviderChain` falls through to next provider on exception | Unit |
| `CredentialProviderChain` throws with last exception as `previous` if all fail | Unit |
| `CredentialProviderChain` rejects empty provider list | Unit |
| `PrefixDatabaseFilter` hides matching databases | Unit |
| `TenantSchemaFilter` restricts to tenant prefix | Unit |
| `SchemaFilter` integrated with `SchemaManager::getDatabases()` | Integration |

---

## 6. File Summary

| File | New/Modified |
|---|---|
| `src/Execution/InMemoryQueryHistory.php` | 🆕 New |
| `src/Execution/NullQueryHistory.php` | 🆕 New |
| `src/Connection/CredentialProviderChain.php` | 🆕 New |
| `src/Contracts/Schema/SchemaFilterInterface.php` | 🆕 New |
| `src/Schema/PrefixDatabaseFilter.php` | 🆕 New |
| `src/Schema/TenantSchemaFilter.php` | 🆕 New |
| `src/Schema/NullSchemaFilter.php` | 🆕 New |
| `tests/Execution/InMemoryQueryHistoryTest.php` | 🆕 New |
| `tests/Execution/NullQueryHistoryTest.php` | 🆕 New |
| `tests/Connection/CredentialProviderChainTest.php` | 🆕 New |
| `tests/Schema/PrefixDatabaseFilterTest.php` | 🆕 New |
