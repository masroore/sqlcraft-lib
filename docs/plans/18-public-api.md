# 18 — Public API

> **Status:** Design draft
> **Scope:** Consumer-facing entry points, facades, end-to-end usage, ergonomics, discoverability, error handling, framework integration, versioning
> **Depends on terminology from:** `05-domain-model.md` (VOs/DTOs/Collections), `06-package-architecture.md` (bounded contexts), `07-module-breakdown.md` (module public surfaces), `08-driver-architecture.md` (DriverInterface/PlatformInterface/DriverRegistry), `09-capability-model.md` (Capability/CapabilitySet)

---

## 1. Design Goals

The public API is the only thing most consumers will ever see. Everything in docs 05-09 exists to make this layer possible, but the layer itself has never been named. This document names it.

Goals, in priority order:

1. **No globals, no statics holding state, no singletons.** Every object a consumer touches is constructed via DI and passed explicitly. `DriverRegistry` (08 §8) is the one deliberate exception — it is a stateless *lookup* table for driver factories, not mutable session state, and consumers rarely touch it directly (see §9).
2. **One obvious entry point per workflow.** A developer reading the README should be able to guess the next method call from IDE autocomplete alone.
3. **Typed all the way down.** No method in the public surface returns `array` of loosely-typed data, `mixed`, or `stdClass`. Every return is a VO, DTO, typed Collection (05 §3-6), or a `void`/scalar.
4. **Composable, not monolithic.** The root object is a thin aggregate over the services described in `07-module-breakdown.md` §5-10. It does not reimplement logic; it wires it.
5. **Identical shape across engines.** The same method calls work against MySQL, PostgreSQL, SQLite, MSSQL, Oracle — only the injected `Connection` differs. This is the payoff of the hexagonal boundary in `06-package-architecture.md` §2.
6. **Framework-agnostic, framework-friendly.** SQLCraft never depends on a framework, but every popular framework's DI container should be able to wire it in under 10 lines.

---

## 2. The Root Entry Point: `DatabaseSession`

### 2.1 Why not a static facade

A Laravel-style static facade (`SQLCraft::connect(...)`) was considered and **rejected**:

| Concern | Static facade | DI-constructed object (chosen) |
|---|---|---|
| Testability | Requires swapping a global resolver in tests | Pass a test double to the constructor |
| Multiple connections | Awkward — facade implies one active connection | Natural — construct N sessions |
| Coexistence in one process | Breaks if two packages both "activate" a facade | Each session is independent |
| Matches "opposite of Adminer" goal (00/01) | No — reintroduces global `$driver`-like state | Yes |

SQLCraft's entire architectural pitch (`06-package-architecture.md` §5) is that Adminer's global `$driver`/`$connection`/`$adminer` state is the thing being fixed. A static facade would quietly reintroduce it. It is rejected outright, not just de-prioritized.

### 2.2 The chosen shape: a factory produces an immutable session aggregate

```php
namespace SQLCraft;

use SQLCraft\Driver\DriverRegistry;
use SQLCraft\ValueObjects\ConnectionParameters;
use SQLCraft\Contracts\Connection\ConnectionInterface;

final readonly class SQLCraftFactory
{
    public function __construct(
        private DriverRegistry $drivers,
        private ?\Psr\EventDispatcher\EventDispatcherInterface $events = null,
        private ?\Psr\SimpleCache\CacheInterface $metadataCache = null,
        private ?\Psr\Log\LoggerInterface $logger = null,
    ) {}

    /** Open a new connection and return a fully-wired session. No global state is touched. */
    public function connect(string $driverName, ConnectionParameters $params): DatabaseSession
    {
        $driver = $this->drivers->get($driverName);
        $connection = $driver->connect($params);

        return new DatabaseSession(
            connection: $connection,
            platform: $driver->getPlatform($connection),
            events: $this->events,
            metadataCache: $this->metadataCache,
            logger: $this->logger,
        );
    }

    /** Wrap an already-open ConnectionInterface (e.g. handed to you by a pool). */
    public function fromConnection(ConnectionInterface $connection, string $driverName): DatabaseSession
    {
        $driver = $this->drivers->get($driverName);
        return new DatabaseSession($connection, $driver->getPlatform($connection), $this->events, $this->metadataCache, $this->logger);
    }
}
```

`SQLCraftFactory` is the **composition root** — the one place adapters (`Driver`, `Platform`, `Connection` concretes, per `06-package-architecture.md` §2) are named directly. Everything downstream of `connect()` speaks only interfaces.

`DatabaseSession` is the object a consumer actually holds and threads through their application:

```php
namespace SQLCraft;

final readonly class DatabaseSession
{
    public function __construct(
        private ConnectionInterface $connection,
        private PlatformInterface   $platform,
        private ?EventDispatcherInterface $events = null,
        private ?CacheInterface $metadataCache = null,
        private ?LoggerInterface $logger = null,
    ) {}

    public function schema(): SchemaManager { /* aggregates MetadataService + SchemaInspector, 07 §5/9 */ }
    public function ddl(): DdlManager { /* aggregates DdlBuilder + Executor, 07 §9 */ }
    public function query(): QueryManager { /* aggregates QueryBuilder + Executor, 07 §9 */ }
    public function export(): ExporterInterface { /* 07 §10 */ }
    public function import(): ImporterInterface { /* 07 §10 */ }
    public function security(): SecurityGuardInterface { /* 07 §10 */ }
    public function capabilities(): CapabilitySet { /* 09 §3-4 */ }
    public function transaction(): TransactionManager { /* 07 §5 */ }
    public function connection(): ConnectionInterface { /* escape hatch, still never PDO */ }
    public function platformName(): string { return $this->platform->getName(); }
}
```

`DatabaseSession` is `readonly` and holds no *mutable* state — it is an aggregate of already-constructed, stateless-or-connection-scoped services. Calling `->schema()` twice returns functionally equivalent (not necessarily `===`) manager instances; managers themselves hold no cross-call state beyond the injected connection/platform.

**Decision — aggregate object vs a dozen separate constructor injections:** Consumers of a DI container *could* instead inject `MetadataServiceInterface`, `DdlBuilderInterface`, etc. directly into their own services (and framework integrations in §8 do exactly that for testability). `DatabaseSession` exists as a **convenience aggregate for scripts, CLIs, and quick usage**, not as a mandatory layer. Both styles are public API; §7 documents which classes are stable either way.

---

## 3. End-to-End Workflows

Every example below is realistic, runnable-shaped PHP. Only the `connect()` call differs between engines — every line after it is identical.

### 3.1 Connect to each engine

```php
$factory = new SQLCraftFactory(new DriverRegistry());
// DriverRegistry ships pre-registered with the 6 built-in drivers (08 §8);
// no manual DriverRegistry::register() call needed for built-ins.

$mysql = $factory->connect('mysql', new ConnectionParameters(
    host: 'db.internal', port: 3306, database: 'shop', username: 'app', password: $secret,
));

$pgsql = $factory->connect('pgsql', new ConnectionParameters(
    host: 'db.internal', port: 5432, database: 'shop', username: 'app', password: $secret,
));

$sqlite = $factory->connect('sqlite', new ConnectionParameters(
    database: '/var/data/shop.sqlite3',
));

$mssql = $factory->connect('sqlserver', new ConnectionParameters(
    host: 'db.internal', port: 1433, database: 'shop', username: 'app', password: $secret,
));

$oracle = $factory->connect('oracle', new ConnectionParameters(
    host: 'db.internal', port: 1521, database: 'ORCLPDB1', username: 'app', password: $secret,
));
```

Named arguments (PHP 8) are used throughout — `ConnectionParameters` (05 §3.10 via 07) has many optional fields (ssl, charset, driver-specific extras) and positional calls would be unreadable and error-prone.

### 3.2 List databases / schemas / tables

```php
$databases = $mysql->schema()->listDatabases();     // DatabaseCollection (07 §4)
$tables    = $mysql->schema()->listTables('shop');  // TableCollection, keyed by table name

foreach ($tables as $table) {
    // $table is SQLCraft\DTO\TableStatus (05 §4.2) — never an array
    printf("%s (%s, ~%d rows)\n", $table->name, $table->engine, $table->rows ?? 0);
}

// PostgreSQL/MSSQL/Oracle additionally expose named schemas:
$schemas = $pgsql->schema()->listSchemas('shop');   // SchemaMeta collection (07 §4)
$tables  = $pgsql->schema()->listTables('shop', schema: 'public');
```

`listTables()` returns a `TableCollection` immediately usable in `foreach`, `filter()`, `map()` per the Collections module (05 §6, 07 §4).

### 3.3 Introspect a table's full structure

```php
$structure = $mysql->schema()->describeTable('shop', 'orders');

$structure->columns;      // ColumnCollection<ColumnMeta>   (05 §4.1)
$structure->indexes;      // IndexCollection<IndexMeta>
$structure->foreignKeys;  // ForeignKeyCollection<ForeignKeyMeta> (05 §4.3)
$structure->triggers;     // TriggerCollection<TriggerMeta>
$structure->status;       // TableStatus (05 §4.2)

foreach ($structure->columns as $column) {
    echo $column->name, ' ', $column->dataType->name, $column->nullable ? ' NULL' : ' NOT NULL', "\n";
}
```

`describeTable()` is a single call that batches column/index/FK/trigger introspection (see `21-performance.md` §7 for why this is one round-trip-set, not four separate N+1 calls). It returns a `TableStructure` DTO — a small aggregate DTO not previously named in 05, added here as the natural return shape for "full structure" (columns + indexes + foreignKeys + triggers + status), consistent with 05 §4's DTO conventions (immutable, `readonly`, one field per concern).

### 3.4 Build and execute a CREATE TABLE

```php
use SQLCraft\ValueObjects\{Identifier, DataType, QualifiedName};
use SQLCraft\DTO\ColumnMeta;

$ddl = $mysql->ddl()->createTable(new QualifiedName(new Identifier('invoices')))
    ->column('id', DataType::int(), autoIncrement: true, primary: true)
    ->column('customer_id', DataType::int(), nullable: false)
    ->column('total_cents', DataType::bigint(), nullable: false)
    ->column('created_at', DataType::timestamp(), nullable: false)
    ->foreignKey(['customer_id'], references: 'customers', columns: ['id'])
    ->engine('InnoDB'); // no-op / ignored on engines without storage engines

$sql = $ddl->toSql();          // string — inspectable before executing
$result = $mysql->ddl()->execute($ddl); // DdlExecutionResult; emits DdlExecutedEvent (05 §9)
```

`createTable()` returns a fluent, *mutable-during-construction* builder (see §5) that materialises into an immutable `CreateTableStatement` VO on `->toSql()`/`->execute()`. Building is deliberately fluent; the built artifact is immutable.

### 3.5 Alter a table

```php
$alter = $mysql->ddl()->alterTable(new QualifiedName(new Identifier('invoices')))
    ->addColumn('paid_at', DataType::timestamp(), nullable: true)
    ->dropColumn('legacy_flag')
    ->renameColumn('total_cents', 'total_amount_cents');

$mysql->ddl()->execute($alter);
```

### 3.6 Browse / select data with filters and pagination (streamed)

```php
$result = $mysql->query()->from('orders')
    ->where('status', '=', 'pending')
    ->where('created_at', '>=', $since)
    ->orderBy('created_at', 'desc')
    ->paginate(pageSize: 200);

foreach ($result->rows() as $row) {
    // $row is a typed Row DTO (column-name-keyed, per 05 DTO conventions); never a bare array.
    echo $row->get('id'), "\n";
}

echo $result->pageInfo()->hasMore ? 'more pages exist' : 'last page';
```

`rows()` returns a `\Generator`, backed by an unbuffered/streamed cursor — see `21-performance.md` §2-3 for the memory model and §5 for why keyset pagination is offered as an alternative to `paginate()`'s OFFSET-based convenience method for very large offsets.

### 3.7 Run arbitrary SQL in a transaction

```php
$mysql->transaction()->run(function (ConnectionInterface $tx) use ($mysql) {
    $tx->execute('UPDATE accounts SET balance = balance - :amt WHERE id = :id', ['amt' => 500, 'id' => 1]);
    $tx->execute('UPDATE accounts SET balance = balance + :amt WHERE id = :id', ['amt' => 500, 'id' => 2]);
});
// Automatically committed on normal return, rolled back on any thrown exception.

// Manual control when a closure shape doesn't fit:
$mysql->transaction()->begin();
try {
    $mysql->query()->raw('DELETE FROM sessions WHERE expires_at < NOW()')->execute();
    $mysql->transaction()->commit();
} catch (QueryException $e) {
    $mysql->transaction()->rollback();
    throw $e;
}
```

### 3.8 Export a table to SQL and to CSV

```php
$mysql->export()->table('orders')->toSqlFile('/tmp/orders.sql');
$mysql->export()->table('orders')->toCsvFile('/tmp/orders.csv', delimiter: ',');

// Streaming to any PSR-7-independent writable stream (no HTTP coupling — SQLCraft never touches HTTP):
$stream = fopen('php://temp', 'w+');
$mysql->export()->table('orders')->toSql($stream);
```

### 3.9 Import a SQL file

```php
$progress = $mysql->import()->sqlFile('/tmp/orders.sql')->run();
echo "{$progress->statementsExecuted} statements, {$progress->rowsAffected} rows affected\n";
```

`run()` streams the file statement-by-statement (`21-performance.md` §3) rather than loading it whole into memory, and emits `ImportProgressEvent` (05 §9) periodically for progress bars.

### 3.10 Check a capability before an operation

```php
use SQLCraft\Capabilities\Capability;

if ($mysql->capabilities()->has(Capability::CheckConstraints)) {
    $ddl = $mysql->ddl()->alterTable($table)->addCheckConstraint('positive_total', 'total_cents > 0');
    $mysql->ddl()->execute($ddl);
} else {
    $logger->warning('CHECK constraints unavailable on this MySQL version; skipping.');
}
```

See `09-capability-model.md` §5 for the `has()` (boolean) vs `require()` (throwing) distinction — `has()` is the right choice here because there is a legitimate fallback path.

### 3.11 The engine-swap guarantee, shown directly

```php
function reportOrderCount(DatabaseSession $db): int
{
    return $db->query()->from('orders')->count();
}

reportOrderCount($mysql);  // works
reportOrderCount($pgsql);  // works — identical call, different platform underneath
reportOrderCount($sqlite); // works
```

This is the concrete demonstration of `06-package-architecture.md`'s hexagonal boundary: `reportOrderCount()` never names a driver, platform, or PDO type.

---

## 4. API Ergonomics: Fluent Builders vs Immutable Withers

Both patterns appear in the public API, deliberately, for different purposes:

| Pattern | Used for | Why |
|---|---|---|
| **Fluent, mutable-during-construction builder** | `DdlBuilder` (createTable/alterTable), `QueryBuilder` | Building a statement is inherently step-wise and often conditional (`if ($includeDeleted) { $qb->where(...); }`). A builder that mutates itself, then freezes into an immutable VO on `->toSql()`, is the standard idiom (Doctrine DBAL, Symfony QueryBuilder) and consumers expect it. |
| **Immutable "wither" (`with*`/`clone with`)** | `ConnectionParameters`, `DataType`, `ColumnMeta` derivations, `QueryBuilder`'s *terminal* immutable result | Once a VO/DTO exists, consumers should never wonder whether calling a method on it changed a copy someone else is holding. `05-domain-model.md` §5 already establishes `clone with` as the sole mutation path for VOs/DTOs. |

**Rule:** anything that *is* a value (VO/DTO/Collection, per 05) is immutable and returns new instances from any transforming method. Anything that *builds toward* a value (a `*Builder` class) is allowed to mutate its own internal buffer while being built, but its `->toSql()`/`->build()`/`->execute()` output is always an immutable artifact. No public method returns a builder that silently shares mutable internal arrays with a previously-returned builder (no aliasing bugs from `$qb2 = $qb->where(...); $qb->where(...)` clobbering `$qb2`) — builder methods that add a clause return `$this` for fluency but a `clone()`-based "branch a builder" method (`withBranch()`) is provided for the rare case a consumer wants two divergent queries from a shared prefix.

```php
// Builder branching — avoids the aliasing trap
$base = $mysql->query()->from('orders')->where('status', '=', 'pending');
$today = $base->withBranch()->where('created_at', '>=', $todayStart);
$thisWeek = $base->withBranch()->where('created_at', '>=', $weekStart);
// $base itself is untouched by either branch.
```

Named arguments are used pervasively wherever a method has more than two optional parameters (`column()`, `ConnectionParameters`, `paginate()`) — this is a PHP 8 ergonomic win Adminer's PHP 5.3-compatible codebase could never use.

---

## 5. Discoverability

A consumer who has never read these docs should be able to find everything through the IDE alone:

1. **`DatabaseSession` is the map.** Its eight public methods (`schema()`, `ddl()`, `query()`, `export()`, `import()`, `security()`, `capabilities()`, `transaction()`) are the entire vocabulary of what SQLCraft does. Autocomplete on `$db->` shows the whole feature set.
2. **`SchemaManager` aggregates introspection.** `$db->schema()->` autocompletes to `listDatabases()`, `listSchemas()`, `listTables()`, `describeTable()`, `describeView()`, `describeRoutine()`, `compare()` (schema diff, per `SchemaInspectorInterface`, 07 §5/9) — a consumer never needs to know that `MetadataService` and `SchemaInspector` are two separate internal services (07 §5, §9).
3. **Capability discovery is a first-class call**, not a side lookup: `$db->capabilities()` returns the full `CapabilitySet` (09 §3), and `CapabilitySet::toArray()` (09 §8) lets a consumer enumerate everything a connected engine+version supports — useful for building admin UIs or feature-flagging application logic without hardcoding an engine/version matrix themselves.
4. **PHPDoc + PHPStan generics** (05 §6, 07 §4) mean IDEs correctly infer `ColumnMeta` as the iterated type of a `ColumnCollection` — no `@return array` ever appears in the public surface.
5. **`composer.json`'s `psr-4` map plus one namespace per bounded context** (06 §3, formalized in `19-package-structure.md`) means "where does the FK stuff live" is answerable by namespace alone: `SQLCraft\DTO\ForeignKeyMeta`, not a guess.

---

## 6. Error Handling From the Consumer's View

All exceptions thrown by SQLCraft extend `SQLCraft\Exceptions\SQLCraftException` (05 §9). The public API commits to **never throwing a non-SQLCraft exception** for expected failure modes — a `\PDOException` never escapes past the `Connection` module boundary (07 §5); it is always caught and rethrown as one of the typed exceptions below.

```php
use SQLCraft\Capabilities\CapabilityNotSupportedException;
use SQLCraft\Exceptions\{
    QueryException, ConstraintViolationException, UniqueConstraintException,
    ConnectionException, ObjectNotFoundException,
};

try {
    $mysql->query()->raw('INSERT INTO users (email) VALUES (:email)', ['email' => $email])->execute();
} catch (UniqueConstraintException $e) {
    // $e->constraintName, $e->sql are typed properties (05 §9) — no string-parsing the driver error
    return Response::conflict("Email already registered");
} catch (ConstraintViolationException $e) {
    return Response::badRequest($e->getMessage());
} catch (ConnectionException $e) {
    $logger->critical('DB unreachable', ['exception' => $e]);
    throw $e; // rethrow — this is not a request-level error
}
```

**Consumer-facing exception catalog** (the subset most application code will actually catch; full hierarchy in 05 §9):

| Exception | When | Typed payload |
|---|---|---|
| `ConnectionFailedException` / `AuthenticationException` / `ConnectionLostException` | connect-time or mid-session network/auth failure | host, driver name |
| `SyntaxErrorException` | malformed SQL reaches the engine | `sql`, engine message |
| `UniqueConstraintException` / `ForeignKeyConstraintException` | constraint violation on write | constraint name, table |
| `DeadlockException` | transaction deadlock detected | retryable = true |
| `ObjectNotFoundException` | `describeTable()` on a table that doesn't exist | qualified name requested |
| `CapabilityNotSupportedException` | `require()` guard fails (09 §5.1) | `Capability`, platform, version |
| `InsufficientPrivilegesException` | `SecurityGuard` denies an action | privilege, object |
| `ImportFailedException` / `ExportFailedException` | streaming I/O failure mid-operation | statement/row index reached |

**Design decision — no error codes as the primary signal:** Unlike raw PDO (`errorCode()` strings that vary by driver), the exception *class* is the signal. `DeadlockException` is always `DeadlockException` whether the underlying engine is MySQL or PostgreSQL — the driver layer is responsible for classifying raw SQLSTATE/driver codes into the shared hierarchy (this classification map lives in each `Platform`, not in application code).

---

## 7. What Is Public API vs `@internal`

| Public (stable, SemVer-covered) | `@internal` (may change without a major version bump) |
|---|---|
| `SQLCraft\Contracts\*` interfaces and contract DTOs | `Connection\ConnectionFactory`, `PdoConnection`, `PdoConnectionFactory`, `PdoExceptionTranslator`, `PdoPreparedStatement`, `TransactionManager`, and concrete result adapters |
| `SQLCraft\ValueObjects\*`, `SQLCraft\DTO\*`, `SQLCraft\Collections\*` | `Metadata\*` implementations and `MetadataFactoryInterface` |
| `SQLCraft\Exceptions\*` hierarchy | `DDL\Sqlite\TableRecreationStrategy` |
| `Capability`, `CapabilitySet`, `ExtendedCapability`, and `CapabilityNotSupportedException` | `Platform\SqlitePlatform` M2 implementation stub |
| `Connection\Transaction`, `DriverRegistry`, and built-in driver/platform adapters | Platform SQL template strings and capability-matrix storage shape |
| `Schema\*`, `DDL\*`, `Execution\*`, and `Query\*` service/builder APIs | Any class or method explicitly tagged `@internal` in its docblock |
| `Import\*`, `Export\*`, `Events\*`, `Security\*`, and `Support\*` APIs | Concrete manager constructor signatures when a contract or factory is available |

The convenience `SQLCraftFactory` / `DatabaseSession` aggregate shown in §2.2 is
planned composition-root API, not claimed as an implemented class in this
release. The current release exposes the same typed graph through
`DriverRegistry`, drivers, `SchemaManagerFactory`, and the service constructors.
`SecurityGuardInterface` is also deferred; the shipped security surface is the
construction-time validation and allowlisting layer in `SQLCraft\Security`.


Every `@internal`-tagged class still ships with the package (PHP has no true package-private visibility) but is excluded from the public API surface for BC purposes — see §10.

---

## 8. Framework Integration

Every integration below is intentionally thin: a binding, not a wrapper library. SQLCraft has zero framework-specific code in `src/`; all of the following live in the *consumer's* application or in a thin, optional `sqlcraft/sqlcraft-laravel`-style bridge package (not part of this repository).

### 8.1 Plain PHP / CLI

```php
require __DIR__ . '/vendor/autoload.php';

$factory = new \SQLCraft\SQLCraftFactory(new \SQLCraft\Driver\DriverRegistry());
$db = $factory->connect('sqlite', new \SQLCraft\ValueObjects\ConnectionParameters(database: 'app.sqlite3'));

foreach ($db->schema()->listTables() as $table) {
    echo $table->name, PHP_EOL;
}
```

No container, no framework — the factory pattern degrades gracefully to zero dependencies.

### 8.2 PSR-11 container registration (framework-neutral baseline)

```php
$container->set(\SQLCraft\Driver\DriverRegistry::class, fn () => new \SQLCraft\Driver\DriverRegistry());

$container->set(\SQLCraft\SQLCraftFactory::class, fn (ContainerInterface $c) => new \SQLCraft\SQLCraftFactory(
    drivers: $c->get(\SQLCraft\Driver\DriverRegistry::class),
    events: $c->has(EventDispatcherInterface::class) ? $c->get(EventDispatcherInterface::class) : null,
    metadataCache: $c->has(CacheInterface::class) ? $c->get(CacheInterface::class) : null,
    logger: $c->has(LoggerInterface::class) ? $c->get(LoggerInterface::class) : null,
));

$container->set(\SQLCraft\DatabaseSession::class, fn (ContainerInterface $c) => $c->get(\SQLCraft\SQLCraftFactory::class)
    ->connect('mysql', ConnectionParameters::fromEnv())); // consumer-defined helper, not part of SQLCraft
```

### 8.3 Laravel service provider sketch

```php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use SQLCraft\{SQLCraftFactory, DatabaseSession};
use SQLCraft\Driver\DriverRegistry;
use SQLCraft\ValueObjects\ConnectionParameters;

final class SQLCraftServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DriverRegistry::class, fn () => new DriverRegistry());

        $this->app->singleton(SQLCraftFactory::class, fn ($app) => new SQLCraftFactory(
            $app->make(DriverRegistry::class),
            events: $app->bound('events') ? $app->make(Psr14BridgeDispatcher::class) : null,
        ));

        $this->app->bind(DatabaseSession::class, function ($app) {
            $config = $app->make('config')->get('database.connections.mysql');
            return $app->make(SQLCraftFactory::class)->connect('mysql', ConnectionParameters::fromArray($config));
        });
    }
}
```

Laravel's own `Illuminate\Database` is unaffected — SQLCraft is bound alongside it, not as a replacement, for consumers who want SQLCraft's introspection/DDL/export surface inside a Laravel app.

### 8.4 Symfony bundle / `services.yaml` sketch

```yaml
services:
    SQLCraft\Driver\DriverRegistry: ~

    SQLCraft\SQLCraftFactory:
        arguments:
            $drivers: '@SQLCraft\Driver\DriverRegistry'
            $events: '@event_dispatcher'
            $metadataCache: '@cache.app'
            $logger: '@logger'

    SQLCraft\ValueObjects\ConnectionParameters $primaryDbParams:
        class: SQLCraft\ValueObjects\ConnectionParameters
        arguments:
            $host: '%env(DB_HOST)%'
            $port: '%env(int:DB_PORT)%'
            $database: '%env(DB_NAME)%'
            $username: '%env(DB_USER)%'
            $password: '%env(DB_PASSWORD)%'

    SQLCraft\DatabaseSession:
        factory: ['@SQLCraft\SQLCraftFactory', 'connect']
        arguments: ['mysql', '$primaryDbParams']
```

Symfony's autowiring resolves `EventDispatcherInterface`/`CacheInterface`/`LoggerInterface` automatically since SQLCraft only asks for PSR interfaces.

### 8.5 What "thin" means, concretely

None of the four integrations above contain business logic, error translation, or SQLCraft-specific abstractions — they are wiring only. If a framework bridge package ever needs more than ~30 lines of glue, that is a signal the public API in this document is missing something and should be fixed at the SQLCraft level, not papered over per-framework.

---

## 9. `DriverRegistry` and Third-Party Drivers From the Consumer's Side

Most consumers never call `DriverRegistry` directly — the five built-in v1 engines are pre-registered by `SQLCraftFactory`'s default constructor path. A consumer only touches it to add a third-party driver (08 §9):

```php
$registry = new DriverRegistry(); // built-ins pre-registered
$registry->register(new \Acme\SQLCraftDuckDb\DuckDbDriver());

$factory = new SQLCraftFactory($registry);
$duckdb = $factory->connect('duckdb', new ConnectionParameters(database: ':memory:'));
```

This is the only place in the public API where a concrete third-party adapter class is named directly by the consumer — consistent with `DriverRegistry` being explicitly called out as the sanctioned "adapter naming point" in `06-package-architecture.md` §2 and §6.

---

## 10. Backward Compatibility and Versioning Promise

SQLCraft follows Semantic Versioning against the surface defined in §7 ("Public") only.

- **Within a major version:** no public method signature changes incompatibly, no public class is removed, no public interface gains a new abstract method without a default/trait-provided implementation path (PHP 8.4 interfaces cannot have defaults, so new interface methods are a **major**-version event, not a minor one — this is called out explicitly because it is the sharpest BC edge in an interface-first design).
- **`@internal`-tagged code may change in any release**, including patch releases. Consumers who reach past the public surface (e.g., `new MySQLMetadataFactory()`) do so at their own risk and are told so in the docblock.
- **Deprecation process:** a method/class marked `@deprecated` ships for at least one full minor version cycle before removal in the next major, triggers a `E_USER_DEPRECATED`-equivalent (PSR-3 `notice`-level log via the injected `LoggerInterface`, not a raw PHP deprecation notice, to avoid polluting consumer error logs by default), and is listed in `CHANGELOG.md`.
- **New `Capability` enum cases** are additive and never a breaking change (09 §8) — consumers pattern-matching exhaustively over `Capability::cases()` in a `match` are the one exception; this is documented as a known minor-version risk for exhaustive-match consumers, mitigated by keeping `Capability` additions rare and always announced in release notes.
- **Driver/Platform additions** (new engines) are always additive/minor. **Driver/Platform interface changes** (new required method) are major, per the interface-BC rule above.
- Full mechanics — SemVer scoping, `@internal` enforcement tooling, deprecation tooling — are specified in `19-package-structure.md` §7; this section states the promise, that document states how it is enforced.
SQLEOF
