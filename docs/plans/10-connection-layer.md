# 10 — Connection Layer

> **Status:** Design draft
> **Scope:** `ConnectionInterface`, `ConnectionFactory`, `ConnectionManager`, `ConnectionParams`/`Dsn` VO, `CredentialProvider`, SSL/TLS, connection lifecycle, unbuffered/streaming, `ResultInterface`, error translation, transaction handle, driver plug-in point, `ConnectionPool` seam, concurrency notes
> **Depends on:** 05-domain-model.md (VOs, exception hierarchy), 08-driver-architecture.md (DriverInterface, PlatformInterface), 09-capability-model.md (CapabilitySet)
> **Namespace root:** `SQLCraft\Connection`

---

## 1. Purpose and Design Goals

The connection layer is the boundary between the rest of SQLCraft and PDO. Its job is to:

1. Present a clean, typed interface to the rest of the library without exposing PDO types in any public surface.
2. Support multiple simultaneous named connections to different engines — Adminer's global `$driver` is a single active connection; SQLCraft allows N connections to N engines concurrently.
3. Provide structured credential handling with a pluggable `CredentialProvider` — no hardcoded credentials anywhere.
4. Translate low-level PDO errors into the typed exception hierarchy defined in 05-domain-model.md.
5. Own the PDO resource lifecycle (connect, ping/health, reconnect on stale, close) transparently.

---

## 2. `ConnectionInterface`

This is the clean OO replacement for Adminer's `SqlDb` contract. Application services depend only on this interface; PDO never surfaces past the concrete adapter.

```php
namespace SQLCraft\Contracts\Connection;

use SQLCraft\DTO\ExecutionResult;
use SQLCraft\ValueObjects\{ServerVersion, QualifiedName};
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Connection\Transaction;
use SQLCraft\Connection\Result\ResultInterface;

interface ConnectionInterface
{
    // --- Identity ---

    /** Canonical platform name: 'mysql', 'pgsql', 'sqlite', etc. */
    public function getPlatformName(): string;

    public function getServerVersion(): ServerVersion;

    public function getPlatform(): PlatformInterface;

    /** Named handle assigned by ConnectionManager (null for ad-hoc connections). */
    public function getName(): ?string;

    // --- Execution ---

    /**
     * Execute a non-SELECT statement (INSERT/UPDATE/DELETE/DDL).
     * Parameters MUST be bound; raw SQL with interpolated user input is a violation.
     *
     * @param array<string|int, mixed> $params
     */
    public function execute(string $sql, array $params = []): ExecutionResult;

    /**
     * Execute a SELECT statement and return a result set.
     * Default: buffered (all rows fetched). Pass $streaming=true for a lazy generator.
     *
     * @param array<string|int, mixed> $params
     */
    public function query(string $sql, array $params = [], bool $streaming = false): ResultInterface;

    /**
     * Prepare a statement for repeated execution (returns reusable PreparedStatement).
     */
    public function prepare(string $sql): PreparedStatementInterface;

    // --- Quote helpers (thin delegates to Platform) ---

    /** Quote an identifier using the platform's quoting rules. */
    public function quoteIdentifier(string $name): string;

    /** Quote a scalar value (for logging/display — always prefer bound params for execution). */
    public function quoteValue(mixed $value): string;

    // --- Introspection shortcuts ---

    public function lastInsertId(?string $sequenceName = null): string|int|false;

    public function affectedRows(): int;

    // --- Transaction ---

    public function beginTransaction(string $isolationLevel = ''): Transaction;

    public function inTransaction(): bool;

    // --- Health / lifecycle ---

    public function ping(): bool;

    public function isConnected(): bool;

    public function close(): void;
}
```

**Design decision — no `multi_query` on the interface:** Multi-statement execution is handled by `QueryExecutor` (see 12-query-engine.md) which iterates `next_result()` at a higher level. Exposing `multi_query` on `ConnectionInterface` would leak buffered-vs-streaming complexity into every caller; it belongs in the execution layer.

**Adminer comparison:**
| Adminer `SqlDb` method | SQLCraft equivalent |
|------------------------|---------------------|
| `query($sql, $unbuffered)` | `query($sql, $params, $streaming)` |
| `multi_query($sql)` | `QueryExecutor::executeBatch()` |
| `store_result()` / `next_result()` | `ResultSet` iterator (see §8) |
| `quote($str)` | `quoteValue()` (delegate to platform) |
| `select_db($db)` | `ConnectionParams::$database` — set at connection time |
| `server_info` property | `getServerVersion()` method |
| `affected_rows` property | `affectedRows()` method |
| `errno` / `error` properties | Typed exceptions (see §9) |
| `attach(server, user, pass)` | `ConnectionFactory::connect()` with `ConnectionParams` |

---

## 3. `ConnectionParams` and `Dsn` Value Objects

Connection parameters are a readonly VO — not a mutable builder, not a raw associative array. The VO validates inputs on construction and is the single source of truth for everything needed to build a DSN.

```php
namespace SQLCraft\Connection;

use SQLCraft\ValueObjects\Credential;

final readonly class ConnectionParams
{
    public function __construct(
        public readonly string       $driver,          // 'mysql', 'pgsql', 'sqlite', etc.
        public readonly ?string      $host       = null,
        public readonly ?int         $port       = null,
        public readonly ?string      $socket     = null, // Unix socket path; overrides host/port
        public readonly ?string      $database   = null,
        public readonly ?string      $charset    = null,
        public readonly ?SslOptions  $ssl        = null,
        public readonly array        $pdoOptions = [],   // PDO::ATTR_* overrides
        public readonly array        $driverOptions = [], // driver-specific extras
        public readonly ?Credential  $credential  = null, // see §4
    ) {
        if ($host === null && $socket === null && $driver !== 'sqlite') {
            throw new \InvalidArgumentException('ConnectionParams: host or socket required.');
        }
    }
}
```

**`Dsn` VO** is produced by the driver, not the application. Application code never constructs a raw DSN string:

```php
final readonly class Dsn
{
    public function __construct(public readonly string $value) {}
    public function __toString(): string { return $this->value; }
}
```

The driver's `buildDsn(ConnectionParams): Dsn` method (see 08-driver-architecture.md §2) is the only place DSN strings are assembled. This keeps engine-specific DSN syntax contained.

**`SslOptions` VO:**

```php
final readonly class SslOptions
{
    public function __construct(
        public readonly ?string $caFile      = null,
        public readonly ?string $certFile    = null,
        public readonly ?string $keyFile     = null,
        public readonly bool    $verifyPeer  = true,
        public readonly ?string $cipherList  = null,
    ) {}
}
```

SSL options are passed via PDO driver-specific attributes (e.g., `PDO::MYSQL_ATTR_SSL_CA`). The concrete driver adapter maps `SslOptions` fields to the correct `PDO::ATTR_*` constants internally.

---

## 4. `CredentialProvider` — Pluggable Credential Injection

SQLCraft stores no credentials. It accepts a `CredentialProvider` and asks it at connection time. This is the explicit boundary between the library (which must never own secrets) and the consumer application (which knows where secrets live).

```php
namespace SQLCraft\Contracts\Connection;

interface CredentialProviderInterface
{
    /**
     * Return the credential for a named connection.
     * Called once per connection attempt; implementors may cache internally.
     *
     * @throws CredentialNotFoundException if the named connection is unknown.
     */
    public function getCredential(string $connectionName): Credential;
}
```

```php
namespace SQLCraft\Connection;

final readonly class Credential
{
    public function __construct(
        public readonly string  $username,
        #[\SensitiveParameter]
        public readonly string  $password,
    ) {}
}
```

The `#[\SensitiveParameter]` attribute (PHP 8.2+) prevents the password from appearing in stack traces. SQLCraft's built-in exception formatter additionally redacts `Credential` objects (see 15-security.md §6).

**Built-in providers** (reference implementations; consumers supply their own):

| Class | Source |
|-------|--------|
| `ArrayCredentialProvider` | In-memory array; test/dev only |
| `EnvCredentialProvider` | `$_ENV` / `getenv()`; simple deployments |
| `CallbackCredentialProvider` | Accepts a `Closure` — integrates with any DI container |

**What SQLCraft explicitly excludes:** Adminer stores credentials in an XXTEA-encrypted cookie for "permanent login". This is a web-application concern. SQLCraft has no session, cookie, or HTTP layer. The consumer application owns credential persistence and injects a provider. See 15-security.md §7 for the full shared-responsibility breakdown.

---

## 5. `ConnectionFactory` and `ConnectionManager`

### 5.1 `ConnectionFactory`

The factory creates a single `Connection` from `ConnectionParams`:

```php
namespace SQLCraft\Connection;

final class ConnectionFactory
{
    public function __construct(
        private readonly DriverRegistryInterface  $registry,
        private readonly CredentialProviderInterface $credentials,
        private readonly ?EventDispatcherInterface $events = null,
    ) {}

    /**
     * Create and return an open connection.
     * The driver builds the DSN; this class never touches PDO directly.
     */
    public function connect(ConnectionParams $params, string $name = ''): ConnectionInterface
    {
        $driver = $this->registry->get($params->driver);
        $conn   = $driver->connect($params->withCredential(
            $this->credentials->getCredential($name ?: $params->driver),
        ));
        $this->events?->dispatch(new ConnectionOpenedEvent($name, $params->driver));
        return $conn;
    }
}
```

### 5.2 `ConnectionManager`

The manager holds named references to open connections — the multi-connection capability absent from Adminer:

```php
namespace SQLCraft\Connection;

final class ConnectionManager
{
    /** @var array<string, ConnectionInterface> */
    private array $connections = [];

    public function __construct(
        private readonly ConnectionFactory $factory,
    ) {}

    /** Register a pre-built connection under a name. */
    public function add(string $name, ConnectionInterface $conn): void
    {
        $this->connections[$name] = $conn;
    }

    /**
     * Get or lazily open a named connection.
     * $params is only used if the connection does not yet exist.
     */
    public function get(string $name, ?ConnectionParams $params = null): ConnectionInterface
    {
        if (!isset($this->connections[$name])) {
            if ($params === null) {
                throw ConnectionNotFoundException::forName($name);
            }
            $this->connections[$name] = $this->factory->connect($params, $name);
        }
        return $this->connections[$name];
    }

    /** @return list<string> */
    public function getNames(): array { return array_keys($this->connections); }

    public function closeAll(): void
    {
        foreach ($this->connections as $conn) {
            $conn->close();
        }
        $this->connections = [];
    }
}
```

**Use case for multiple connections:** A migration tool compares schema on a production MySQL instance and a staging PostgreSQL instance simultaneously. Both `ConnectionInterface` objects coexist in `ConnectionManager`; no global state is involved.

---

## 6. Connection Lifecycle

### 6.1 Lazy Connect

By default the `PdoConnection` adapter defers PDO instantiation until the first `execute()` or `query()` call. This allows `ConnectionManager::add()` to register parameters without paying the TCP handshake cost up-front. Applications that need an eager connection call `ping()` explicitly.

**Decision — lazy vs eager:** Lazy wins for API servers and CLI tools that register connections at bootstrap but may not use all of them on every request. Eager is trivially available via `ping()` as a health check.

### 6.2 Ping and Health Check

```php
public function ping(): bool
{
    try {
        $this->execute('SELECT 1');
        return true;
    } catch (ConnectionException) {
        return false;
    }
}
```

Implementations may use a driver-specific ping (`PDO::ATTR_PING` is not standard; MySQL uses `mysqli_ping` equivalent; others use `SELECT 1`). The interface hides this.

### 6.3 Reconnect Policy

`ConnectionInterface` does not auto-reconnect — that is the application's responsibility. The reasoning:

- Auto-reconnect can silently swallow a connection reset that happens mid-transaction, causing data loss.
- Different applications have different retry/backoff requirements.

Instead, `ConnectionManager` may be wrapped in a `RetryingConnectionManager` by the consumer, which calls `ping()` before delegating and reconnects if needed. SQLCraft ships a `RetryDecorator` as a reference implementation (not in `ConnectionManager` itself — composition over inheritance).

### 6.4 Close

`close()` calls `PDO::__destruct()` semantics — it does not forcibly terminate the server-side connection (PDO/PHP cleanup handles that), but it marks the `Connection` as closed and prevents further use. Subsequent calls throw `ConnectionClosedException`.

---

## 7. Unbuffered vs Buffered Queries

Adminer passes an `$unbuffered` boolean directly to its `query()` method. SQLCraft exposes this as `$streaming` on `ConnectionInterface::query()`. The semantics are identical; the naming is clearer.

| Mode | PDO attribute | Memory usage | Cursor | Suitable for |
|------|--------------|--------------|--------|--------------|
| Buffered (default) | `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY = true` | O(rows) | Free-seek | Small result sets, column metadata needed up-front |
| Streaming | `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY = false` | O(1) | Forward-only | Large exports, data browsing with row caps, AI agent data ingestion |

**Implementation note:** SQLite and PostgreSQL do not have the buffered/unbuffered distinction at the PDO level; they always stream. The `$streaming` flag controls whether `ResultInterface` returns a `Generator` (streaming) or an array-backed result (buffered). The concrete `PdoConnection` adapts per platform.

---

## 8. `ResultInterface`

Replaces Adminer's `PdoResult extends PDOStatement` which leaked PDO types. `ResultInterface` is a clean abstraction over a result cursor.

```php
namespace SQLCraft\Contracts\Connection;

/**
 * An immutable, forward-iterable result from a SELECT query.
 * @extends \IteratorAggregate<int, array<string, mixed>>
 */
interface ResultInterface extends \IteratorAggregate, \Countable
{
    /**
     * Fetch the next row as an associative array.
     * Returns null when exhausted.
     * @return array<string, mixed>|null
     */
    public function fetchAssoc(): ?array;

    /**
     * Fetch the next row as a numeric array.
     * @return list<mixed>|null
     */
    public function fetchRow(): ?array;

    /**
     * Fetch all rows. Caution on large result sets.
     * @return list<array<string, mixed>>
     */
    public function fetchAll(): array;

    /**
     * Fetch a single column from all rows.
     * @return list<mixed>
     */
    public function fetchColumn(int|string $column = 0): array;

    /**
     * Column metadata: name, native type, table (where available).
     * @return list<ResultColumn>
     */
    public function getColumns(): array;

    /**
     * Seek to a row index (only valid for buffered results).
     * @throws StreamingResultException if called on a streaming result.
     */
    public function seek(int $offset): void;

    public function isStreaming(): bool;

    /** For buffered results: total row count. For streaming: throws. */
    public function count(): int;

    /** Row iteration (works for both buffered and streaming). */
    public function getIterator(): \Traversable;
}
```

```php
final readonly class ResultColumn
{
    public function __construct(
        public readonly string  $name,
        public readonly ?string $nativeType,
        public readonly ?string $table,
        public readonly ?int    $length,
        public readonly bool    $nullable,
    ) {}
}
```

**Adminer `PdoResult` mapping:**
| Adminer method | SQLCraft equivalent |
|----------------|---------------------|
| `fetch_assoc()` | `fetchAssoc()` |
| `fetch_row()` | `fetchRow()` |
| `fetch_field()` | `getColumns()` returns typed `ResultColumn` objects |
| `seek($row)` | `seek($offset)` |
| `num_rows` property | `count()` (buffered only) |

---

## 9. Error Translation

PDO throws `PDOException` with engine-specific SQLSTATE codes in `$e->getCode()`. SQLCraft maps these to the typed hierarchy from 05-domain-model.md at the `PdoConnection` adapter layer. Application services catch `QueryException` subtypes, never `PDOException`.

```php
namespace SQLCraft\Connection;

/** @internal */
final class PdoExceptionTranslator
{
    public function translate(\PDOException $e, string $sql = ''): \SQLCraft\Exceptions\SQLCraftException
    {
        $sqlstate = (string) $e->getCode();
        $native   = $e->errorInfo[1] ?? 0;

        return match(true) {
            str_starts_with($sqlstate, '08') => new ConnectionLostException($e->getMessage(), previous: $e),
            $sqlstate === '28000'            => new AuthenticationException($e->getMessage(), previous: $e),
            $sqlstate === '42000'            => new SyntaxErrorException($sql, $e->getMessage(), previous: $e),
            $sqlstate === '23000'            => $this->translateConstraint($native, $e),
            $sqlstate === '40001'            => new DeadlockException($e->getMessage(), previous: $e),
            default                          => new QueryException($e->getMessage(), $sql, previous: $e),
        };
    }

    private function translateConstraint(int $native, \PDOException $e): \SQLCraft\Exceptions\QueryException
    {
        // MySQL 1062 = unique; 1452 = FK; MariaDB/PgSQL/SQLite have different native codes
        return match($native) {
            1062, 2627 => new UniqueConstraintException($e->getMessage(), previous: $e), // MySQL + MSSQL
            1452, 547  => new ForeignKeyConstraintException($e->getMessage(), previous: $e),
            default    => new ConstraintViolationException($e->getMessage(), previous: $e),
        };
    }
}
```

The translator is injected into `PdoConnection` and called in every `execute()`/`query()` catch block. Application code never sees `PDOException`.

---

## 10. Transaction Handle

Transactions are returned as value objects from `beginTransaction()`. This prevents callers from accidentally nesting raw `PDO::beginTransaction()` calls.

```php
namespace SQLCraft\Connection;

final class Transaction
{
    private bool $committed = false;
    private bool $rolledBack = false;

    public function __construct(
        private readonly ConnectionInterface $conn,
        public readonly string $isolationLevel = '',
        public readonly ?string $savepointName  = null, // non-null = nested savepoint
    ) {}

    public function commit(): void
    {
        if ($this->savepointName !== null) {
            $this->conn->execute("RELEASE SAVEPOINT {$this->savepointName}");
        } else {
            $this->conn->execute('COMMIT');
        }
        $this->committed = true;
    }

    public function rollback(): void
    {
        if ($this->savepointName !== null) {
            $this->conn->execute("ROLLBACK TO SAVEPOINT {$this->savepointName}");
        } else {
            $this->conn->execute('ROLLBACK');
        }
        $this->rolledBack = true;
    }

    public function isActive(): bool { return !$this->committed && !$this->rolledBack; }
}
```

Nested transactions use savepoints (see 12-query-engine.md §4 for the full `TransactionManager` design). `Transaction` is not a PDO thin wrapper; it knows its own state and prevents double-commit.

---

## 11. `PreparedStatementInterface`

Reusable prepared statements for batch operations (bulk INSERT, repeated UPDATE with different params):

```php
namespace SQLCraft\Contracts\Connection;

interface PreparedStatementInterface
{
    /**
     * Execute with bound parameters.
     * @param array<string|int, mixed> $params
     */
    public function execute(array $params): ExecutionResult;

    /**
     * Execute and return a result set (for SELECT).
     */
    public function query(array $params): ResultInterface;

    public function close(): void;
}
```

`PdoConnection::prepare()` returns a `PdoPreparedStatement` wrapping `PDOStatement`. The wrapped `PDOStatement` is never exposed publicly.

---

## 12. Driver Plug-in Point

The driver's `connect()` method (08-driver-architecture.md §2) is the sole factory for `ConnectionInterface` instances. The concrete `PdoConnection` class is internal to `SQLCraft\Connection\Adapter`. Third-party drivers may return their own `ConnectionInterface` implementation without extending `PdoConnection` — as long as PDO is what powers it underneath.

```php
// How MySQLDriver plugs in (internal, not public API)
final class MySQLDriver implements DriverInterface
{
    public function connect(ConnectionParameters $params): ConnectionInterface
    {
        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_STRINGIFY_FETCHES  => false,
            \PDO::ATTR_EMULATE_PREPARES   => false,
            \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true, // default; overridden per-query
        ];
        if ($params->ssl !== null) {
            $options[\PDO::MYSQL_ATTR_SSL_CA]     = $params->ssl->caFile;
            $options[\PDO::MYSQL_ATTR_SSL_CERT]   = $params->ssl->certFile;
            $options[\PDO::MYSQL_ATTR_SSL_KEY]    = $params->ssl->keyFile;
            $options[\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $params->ssl->verifyPeer;
        }
        $pdo = new \PDO($this->buildDsn($params)->value, $params->credential?->username, $params->credential?->password, $options);
        return new PdoConnection($pdo, $this->getPlatform(...), name: $params->name ?? '');
    }
}
```

---

## 13. `ConnectionPool` Seam (Future)

v1 does not implement pooling — PHP's typical share-nothing FPM model makes per-request PDO connections inexpensive. However, the architecture accommodates future pooling (Swoole, RoadRunner, PgBouncer integration, external poolers) without public API changes.

The seam is a `ConnectionPoolInterface`:

```php
namespace SQLCraft\Contracts\Connection;

interface ConnectionPoolInterface
{
    /** Acquire a connection from the pool (blocking or async). */
    public function acquire(ConnectionParams $params): ConnectionInterface;

    /** Return a connection to the pool. */
    public function release(ConnectionInterface $conn): void;

    public function getStats(): PoolStats;
}
```

`ConnectionManager` can accept a `ConnectionPoolInterface` in place of `ConnectionFactory` via constructor injection — same interface, different lifecycle semantics. In v1, `ConnectionFactory` is the default. A `PdoPersistentConnectionPool` can be added later without touching application services or `ConnectionInterface`.

**PDO persistent connections (`PDO::ATTR_PERSISTENT = true`):** These are a shallow form of pooling built into PDO. SQLCraft does not enable them by default because persistent connections can carry transaction state from a previous request, which is dangerous. The `SslOptions` and `pdoOptions` fields of `ConnectionParams` allow consumers to enable them explicitly with full knowledge of the tradeoffs.

---

## 14. Thread Safety and Concurrency Notes

PHP's FPM model is share-nothing; each request gets its own PDO connection, and `ConnectionManager` instances are per-request. No locking is required in this model.

For long-running processes (Swoole coroutines, ReactPHP, Amp, RoadRunner workers):

- `ConnectionInterface` instances must **not** be shared across coroutines. Each coroutine must acquire its own connection from a pool.
- `ConnectionManager` should be scoped to a coroutine/fiber context, not the worker process.
- All `ResultInterface` streaming generators must be consumed before the connection is returned to a pool.
- `PdoConnection` uses no static or global state — it is safe to instantiate N copies in N fibers.

SQLCraft does not prescribe a coroutine-safety strategy in v1. The design is structured so that adding fiber-safe connection acquisition (via `ConnectionPoolInterface`) requires no changes to `ConnectionInterface` or any service that depends on it.

---

## 15. Summary of Key Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| PDO leakage | Never past `PdoConnection` | Enforces hexagonal boundary; PDO swap possible later |
| Credential storage | `CredentialProvider` interface only | Library never holds secrets; consumer chooses vault/env/etc. |
| Multi-connection | `ConnectionManager` with named handles | Adminer's global driver is a single active connection; SQLCraft supports N concurrent |
| Buffered default | Buffered (with `$streaming=true` opt-in) | Safe default; streaming is opt-in for large data |
| Auto-reconnect | No; consumer decorates | Reconnect inside transaction = data loss risk; different apps need different policies |
| Nested transactions | Savepoints (see 12-query-engine.md) | PDO does not support nested `beginTransaction()` |
| Error translation | `PdoExceptionTranslator` mapping SQLSTATE | Typed exceptions enable structured error handling |
| Connection pool | Interface seam only in v1 | FPM doesn't need it; Swoole/RoadRunner support via composition later |
| Lazy connect | Default | No TCP cost until first use; `ping()` available for eager check |
