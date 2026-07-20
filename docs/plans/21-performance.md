# 21 — Performance & Resource Efficiency

> **Status:** Design draft
> **Scope:** Lazy loading, streaming, caching, connection/statement reuse, pagination strategy, N+1 avoidance, memory ceilings, unbuffered-query tradeoffs, benchmarking, big-schema scale, cache-exclusion zones
> **Depends on terminology from:** `05-domain-model.md` (Collections/LazyCollection, DTOs), `06-package-architecture.md` (bounded contexts), `07-module-breakdown.md` (Metadata/Import/Export modules, `LazyCollection`), `08-driver-architecture.md` (PlatformInterface/IntrospectionDialectInterface), `09-capability-model.md` (Capability), `14-import-export.md` (referenced by name per task brief — streaming import/export detail), `20-testing.md` §9/§10 (memory/streaming test technique, big-schema fixture generation)

---

## 1. Guiding Principle

SQLCraft is a library, not an application — its performance characteristics are inherited wholesale by every consumer (18 §0's Laravel/Symfony/CLI/AI-agent list). A memory or latency mistake made here is multiplied across every application that adopts the library, at every scale from a 5-table SQLite CLI tool to a 10,000-table enterprise MSSQL instance browsed by an AI agent doing bulk schema analysis. Consequently, **the default behavior of every public API method must be the resource-safe one**, and any "load everything eagerly" convenience is opt-in, never the default — the inverse of Adminer's approach, which (as a single-request-scoped web page renderer) never had to think about holding metadata for 10,000 tables in memory at once because a human was never going to scroll through 10,000 table rows in one page load; an AI agent or bulk migration tool calling SQLCraft absolutely will attempt exactly that.

---

## 2. Lazy Loading of Metadata

**Default:** metadata is not fetched until requested. `$db->schema()->listTables('shop')` executes one query and returns a `TableCollection` — but `describeTable()`'s fuller structure (columns, indexes, FKs, triggers, per `18-public-api.md` §3.3) is not fetched merely by knowing a table exists.

```php
$tables = $db->schema()->listTables('shop');   // 1 query: table names + TableStatus only
foreach ($tables as $table) {
    echo $table->name, "\n";                    // no per-table column fetch triggered
}

$full = $db->schema()->describeTable('shop', 'orders'); // now columns/indexes/FKs/triggers fetch
```

**`LazyCollection`** (07 §4) is the mechanism for collections whose *construction itself* would be expensive to do eagerly — e.g., `listTables()` against a schema with thousands of tables returns a collection that wraps a `\Closure` producer and materializes only on first iteration, not at the moment `listTables()` is called. This matters because a consumer who calls `listTables()` purely to get a `count()` via a capability-aware approximate-count path (§6) should not pay for full materialization of every `TableStatus` DTO.

```php
namespace SQLCraft\Collections;

final class LazyCollection implements \IteratorAggregate, \Countable
{
    private ?array $materialized = null;

    public function __construct(private readonly \Closure $producer) {}

    public function getIterator(): \Iterator
    {
        return new \ArrayIterator($this->materialized ??= ($this->producer)());
    }

    public function count(): int
    {
        return count($this->materialized ??= ($this->producer)());
    }
}
```

**Tradeoff, stated explicitly:** `LazyCollection` still fully materializes into an array on first touch — it defers *when* the cost is paid, not *whether* it is paid. For genuinely unbounded result sets (table data rows, not table/column metadata), the correct tool is not a lazy collection but a streamed generator (§3) — the two are not interchangeable, and `Metadata` module methods (07 §8) use `LazyCollection` for "big but ultimately bounded and metadata-shaped" results (table lists, column lists), while `Query`/`Execution` module methods (07 §9) use `\Generator`-backed streaming for "arbitrarily large data" results. Conflating the two would either force full-table-scan-sized data through an eager array (memory blowup) or force metadata operations that legitimately need `count()` without full iteration through generator semantics that don't support cheap re-iteration.

---

## 3. Generators/Streaming — the Core Memory Strategy

**The problem this solves:** loading a query result (or an entire table dump, or an entire import file) into a PHP array before processing it means peak memory scales linearly with row/statement count. A 10-million-row export or a multi-gigabyte SQL import file must never require holding the whole thing in memory — this is the single most important resource-efficiency property SQLCraft commits to.

**The mechanism:** every SQLCraft API that can return an unbounded number of items returns a `\Generator` (or a thin object wrapping one, exposing `\Generator`-equivalent semantics plus typed metadata like `pageInfo()`), not an `array`.

```php
// Query::rows() — 18 §3.6
public function rows(): \Generator
{
    $stmt = $this->connection->executeUnbuffered($this->toSql(), $this->bindings);
    while (($raw = $stmt->fetch()) !== false) {
        yield $this->hydrator->createRow($raw); // typed Row DTO, not a bare array
    }
    $stmt->close(); // release the cursor promptly — see §10
}
```

```php
// Metadata::streamAllTablesAcrossDatabase() — for the "thousands of tables" scenario, §11
public function streamAllTables(string $database): \Generator
{
    foreach ($this->platform->getTablesSql($database) as $sql) {
        // batched per §7 — this is illustrative of the *shape*, not a literal per-row query loop
    }
    yield from $this->batchFetchTableStatuses($database);
}
```

**Contrast — what is explicitly rejected as a default:**

```php
// REJECTED as a default API shape anywhere in SQLCraft:
public function getAllRows(): array   // loads everything, memory scales with row count
{
    return $this->connection->execute($this->toSql())->fetchAll();
}
```

An `array`-returning convenience *is* offered, but only as an explicitly-named, explicitly-bounded opt-in (`->rows()->take(500)->toArray()`-style terminal call, or a `fetchAllCapped(int $maxRows)` method that throws `ResultSetTooLargeException` rather than silently materializing past a safety cap, per §9) — never as the unqualified default return type of a method with a generic name.

**Streaming import** (cross-referencing `14-import-export.md` per the task brief, since import/export detail lives there): the `Importer` (07 §10) reads a SQL/CSV file via a chunked stream reader, parses and executes **statement-by-statement** (or row-batch-by-row-batch for CSV/bulk-insert modes), never buffering the whole file. A 2 GB `.sql` dump is imported with peak memory bounded by the largest single statement in the file, not by the file size.

**Streaming export** mirrors this: `Exporter` (07 §10) writes each fetched row to the destination stream immediately, never accumulating a full dump string/array before writing. Both `toSqlFile()` and `toCsvFile()` (18 §3.8) are implemented as thin wrappers opening a file handle and delegating to the stream-accepting `toSql(resource $stream)`/`toCsv(resource $stream)` methods — the file-path convenience methods are not a separate code path with different memory characteristics.

---

## 4. Metadata Caching (Opt-In, PSR-16)

**Seam:** `DatabaseSession`/`SQLCraftFactory` accept an optional `?CacheInterface $metadataCache` (18 §2.2, PSR-16). When present, `MetadataService` (07 §8) consults it before issuing introspection SQL and writes through after a live fetch.

```php
private function cacheKey(string $database, string $table, string $kind): string
{
    // Namespaced by platform+version so a cache is never cross-contaminated between
    // two DatabaseSessions against different engines/versions sharing one cache backend.
    return sprintf('sqlcraft:meta:%s:%s:%s:%s:%s', $this->platform->getName(), $this->serverVersion, $database, $table, $kind);
}

public function getColumns(ConnectionInterface $conn, QualifiedName $table): ColumnCollection
{
    $key = $this->cacheKey($table->schema?->name ?? '', $table->object->name, 'columns');

    if ($this->cache !== null && ($cached = $this->cache->get($key)) !== null) {
        return $cached; // PSR-16 value — a serialized ColumnCollection, see note below
    }

    $columns = $this->fetchColumnsLive($conn, $table);
    $this->cache?->set($key, $columns, ttl: $this->cacheTtl); // opt-in TTL, default e.g. 300s
    return $columns;
}
```

**Cache key design:** namespaced by platform name + server version + database + table + metadata-kind, so that (a) two different engines/versions never collide, and (b) invalidating "just this table's columns" doesn't require a broader cache flush.

**Invalidation on DDL:** `DdlManager::execute()` (18 §3.4-3.5) invalidates the relevant cache keys for the affected table *synchronously, before returning* — a `CREATE`/`ALTER`/`DROP` against a table always invalidates that table's `columns`/`indexes`/`foreignKeys`/`triggers`/`status` keys, and a schema-level DDL (`CREATE DATABASE`, `DROP SCHEMA`) invalidates the `listTables`/`listSchemas` keys for that scope. This is done via the same `DdlExecutedEvent` (05 §9) the Events module already emits — a `CacheInvalidatingListener` (shipped as an optional, opt-in listener registration, not a hardwired side effect) subscribes to `DdlExecutedEvent` and clears affected keys, keeping the cache-invalidation *mechanism* decoupled from the DDL module itself per the dependency rules in `06-package-architecture.md` §4 (DDL does not depend on a cache implementation).

**TTL:** default TTL is short (minutes, not hours) and always consumer-configurable — metadata caching is a latency optimization for repeated introspection within a short-lived scope (e.g., an AI agent making several calls about the same table within one conversation turn), not a claim that schema metadata is safe to treat as long-lived immutable data (another process/consumer can `ALTER TABLE` at any time without this process's cache knowing, unless it goes through this same `DdlManager` instance's invalidation).

**Opt-in, not default:** if no `CacheInterface` is supplied, every metadata call is a live fetch — this is the safe default, and matches the "near-zero runtime deps, PSR interfaces optional" packaging decision in `19-package-structure.md` §3/§5.

---

## 5. Connection Reuse and Prepared-Statement Reuse

**Connection reuse:** `DatabaseSession` holds one `ConnectionInterface` for its lifetime (18 §2.2) — SQLCraft never silently opens a second connection behind a consumer's back for a single session's operations. `ConnectionPool` (07 §5) is offered for applications that need concurrent connections (e.g., a worker pool), but pooling policy (min/max size, idle timeout, health-check-on-checkout) is the *consumer's* configuration, not a hardcoded SQLCraft default — a library cannot know a host application's concurrency model.

**Prepared-statement reuse:** `PdoConnection` (07 §5) maintains a small internal LRU cache of prepared `\PDOStatement` handles keyed by SQL text, so that a `QueryBuilder` result executed repeatedly with only bindings changing (a common pattern: paginating through a report, or `Importer` running the same parameterized `INSERT` for every row/batch in a file) reuses the already-prepared statement rather than re-preparing identical SQL text on every call. Cache size is bounded (default: 32 statements) and evicts LRU — unbounded growth here would itself be a memory-leak risk in a long-lived worker process holding one `DatabaseSession` across thousands of distinct ad-hoc queries.

```php
final class PreparedStatementCache
{
    /** @var array<string, \PDOStatement> LRU-ordered by insertion/access */
    private array $cache = [];

    public function __construct(private readonly int $maxSize = 32) {}

    public function get(string $sql, \Closure $prepare): \PDOStatement
    {
        if (isset($this->cache[$sql])) {
            $stmt = $this->cache[$sql];
            unset($this->cache[$sql]);
            $this->cache[$sql] = $stmt; // move to end = most-recently-used
            return $stmt;
        }

        $stmt = $prepare($sql);
        if (count($this->cache) >= $this->maxSize) {
            array_shift($this->cache); // evict least-recently-used
        }
        $this->cache[$sql] = $stmt;
        return $stmt;
    }
}
```

---

## 6. Pagination Efficiency

**`LIMIT/OFFSET` degradation at scale:** every engine's `OFFSET n` (or MSSQL's `OFFSET...FETCH`, Oracle's rownum-subquery equivalent, per `08-driver-architecture.md` §3.2) requires the engine to internally scan and discard the first `n` rows before returning the page — cost grows linearly with offset, regardless of page size. `Query::paginate()` (18 §3.6) is offered as the *convenient default* for small-to-moderate offsets (typical UI-style "page 3 of results") precisely because it is simple and universally supported, but its docblock explicitly states the degradation and points to keyset pagination for deep paging.

**Keyset (seek) pagination — the scalable alternative:**

```php
// Instead of OFFSET-based paging into page 5000:
$page = $db->query()->from('orders')
    ->where('id', '>', $lastSeenId)   // seek from the last row of the previous page
    ->orderBy('id', 'asc')
    ->limit(200)
    ->rows();
```

`QueryBuilder` exposes `->seekAfter(mixed $cursorValue, string $column)` as a first-class method (not just "the consumer writes their own WHERE clause," though that remains possible) so keyset pagination is a named, discoverable pattern rather than something a consumer has to know to hand-roll:

```php
$cursor = null;
do {
    $page = $db->query()->from('orders')->orderBy('id')->seekAfter($cursor, 'id')->limit(200)->rows();
    $rows = iterator_to_array($page->rows());
    $cursor = end($rows)?->get('id');
} while (!empty($rows));
```

**Tradeoff table:**

| Strategy | Latency at small offset | Latency at large offset (deep page) | Requires stable sort key | Supports "jump to page N" |
|---|---|---|---|---|
| `LIMIT/OFFSET` (`paginate()`) | Low | Degrades linearly — poor at offset ≫ page size | No | Yes |
| Keyset/seek (`seekAfter()`) | Low | Constant — independent of how many pages precede | Yes (monotonic, indexed column) | No (sequential only) |

**Approximate counts:** `paginate()`'s `PageInfo::totalCount` (18 §3.6) is, by default, an **approximate** count sourced from engine statistics where available (`information_schema.tables.table_rows` estimate for InnoDB, PostgreSQL's `pg_class.reltuples`/`pg_stat_user_tables` estimate) rather than an exact `COUNT(*)` — an exact count on a large table is itself an expensive full/index scan, and most pagination UIs do not need an exact figure to render "about 45,000 results." An exact count is available via an explicit `->exactCount()` opt-in method, whose docblock states the cost tradeoff plainly so the choice is the consumer's, made knowingly, not SQLCraft's silent default.

| Capability required | Count source used |
|---|---|
| Engine exposes cheap statistics (`Capability::Status`, 09 §2) | Approximate, from engine metadata (fast) |
| Engine lacks cheap statistics, or consumer calls `exactCount()` | `SELECT COUNT(*)` (exact, potentially slow on large tables) |

---

## 7. Avoiding N+1 Introspection

**The problem (Adminer's own `allFields()` already recognizes this — 03-adminer-analysis.md, 07 §8 references it explicitly as the pattern to preserve, not reinvent):** introspecting N tables by issuing one `getColumnsSql()` query per table is an N+1 pattern that becomes the dominant cost at the "thousands of tables" scale referenced in §11. SQLCraft's `IntrospectionDialectInterface` (08 §10) is deliberately designed so that **batch introspection across many tables in one round trip is a first-class capability of the interface, not a workaround bolted on top of it.**

```php
interface IntrospectionDialectInterface
{
    // ... per-table methods from 08 §10 ...

    /**
     * Batch variant: fetch columns for ALL tables in the given database/schema in one query,
     * keyed by table name. Every built-in platform implements this via a single
     * INFORMATION_SCHEMA (or catalog-equivalent) query with no per-table WHERE-clause looping.
     * @return array<string, ColumnCollection>
     */
    public function getAllColumnsSql(string $database, ?string $schema = null): string;
    public function getAllIndexesSql(string $database, ?string $schema = null): string;
    public function getAllForeignKeysSql(string $database, ?string $schema = null): string;
}
```

```php
// MetadataService — batch path used by describeAllTables(), the bulk-introspection entry point
public function describeAllTables(ConnectionInterface $conn, string $database): \Generator
{
    // 3 queries total (columns, indexes, FKs), regardless of table count — not 3*N.
    $allColumns = $this->hydrateAllColumns($conn->execute($this->platform->getAllColumnsSql($database)));
    $allIndexes = $this->hydrateAllIndexes($conn->execute($this->platform->getAllIndexesSql($database)));
    $allFks     = $this->hydrateAllForeignKeys($conn->execute($this->platform->getAllForeignKeysSql($database)));

    foreach ($this->fetchTableNames($conn, $database) as $tableName) {
        yield $tableName => new TableStructure(
            columns: $allColumns[$tableName] ?? new ColumnCollection([]),
            indexes: $allIndexes[$tableName] ?? new IndexCollection([]),
            foreignKeys: $allFks[$tableName] ?? new ForeignKeyCollection([]),
        );
    }
}
```

`describeTable()` (18 §3.3, single-table) remains the ergonomic default for "I want one table's structure," implemented as a filtered call to the same batch SQL (WHERE-scoped to one table) rather than a genuinely different code path — this keeps the batch and single-table introspection SQL from drifting apart into two implementations that could disagree. `describeAllTables()` (bulk path) is what an AI agent, migration tool, or schema-diffing feature (Schema module, 07 §9) should reach for whenever it is about to loop over "all tables" and call per-table introspection — the API is designed so the batch method is the one a consumer discovers (§5 of doc 18) right next to `describeTable()`, not buried as an obscure optimization.

---

## 8. Memory Ceilings and Row Caps

**Safety limits exist as configurable, sane-defaulted ceilings, never as silent truncation.** Any method that could return an unbounded amount of data but is *not* generator-based by nature (e.g., a hypothetical convenience `fetchAllCapped()` mentioned in §3, or `LazyCollection`'s materialization in §2) enforces a maximum item count and **throws** rather than silently returning a truncated, incomplete-looking result set that a consumer might mistake for "the whole table."

```php
namespace SQLCraft\Exceptions;

final class ResultSetTooLargeException extends QueryException
{
    public function __construct(
        public readonly int $limit,
        public readonly int $actualOrExceeded,
    ) {
        parent::__construct("Result set exceeds configured cap of {$limit} rows. Use ->rows() for streaming instead of an eager fetch.");
    }
}
```

Defaults (all consumer-overridable via `DatabaseSession` construction or per-call options): eager-fetch row cap 10,000; `LazyCollection` materialization cap for metadata collections 50,000 items (well above realistic "thousands of tables," §11, but present as a genuine circuit breaker against pathological input, e.g., a consumer accidentally pointing `listTables()` at a `%`-wildcard scope on a multi-tenant `INFORMATION_SCHEMA` view spanning far more objects than intended). These are safety nets against *misuse of a non-streaming convenience method*, not limits on SQLCraft's real capacity — the generator-based paths (§3) have no such cap because they do not accumulate memory proportional to row count in the first place.

---

## 9. Unbuffered Queries — Tradeoffs, Stated Explicitly

Streaming (§3) is implemented, where the underlying PDO driver supports it, via **unbuffered queries** (`PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false` for the MySQL PDO driver; PostgreSQL's libpq is unbuffered/cursor-capable by nature; SQLite has no meaningful buffering distinction at this level). This is the mechanism that makes constant-memory streaming possible for the *client side* — the query result is pulled from the server row-by-row rather than the driver eagerly buffering the entire result set client-side before SQLCraft ever sees the first row.

**The documented tradeoff:** while an unbuffered result cursor is open and not yet fully consumed (or explicitly closed), **the same connection cannot be used to run another query.** MySQL's PDO driver specifically raises "commands out of sync" if a second query is attempted on a connection with an open unbuffered cursor. This means:

```php
foreach ($db->query()->from('orders')->rows()->rows() as $order) {
    // WRONG on the same connection while $order's outer cursor is still open:
    $customer = $db->query()->from('customers')->where('id', '=', $order->get('customer_id'))->rows();
    // → throws ConnectionException("commands out of sync") on MySQL specifically.
}
```

**Mitigations SQLCraft provides, in order of preference:**
1. **Batch the inner lookup instead of nesting per-row queries** — this is the same N+1 pattern discussed in §7, and the fix is the same: collect all needed `customer_id`s first, issue one batched `WHERE id IN (...)` query, then join in-memory while streaming the outer result.
2. **Use a second `ConnectionInterface`/`DatabaseSession`** for genuinely independent concurrent access patterns — `ConnectionPool` (§5) exists for exactly this.
3. **Fall back to a buffered query explicitly** (`->rows(buffered: true)`) when a consumer specifically needs to interleave queries on one connection and accepts the memory cost for that particular result set — an explicit, named opt-out, not a silent default switch.

This tradeoff is documented in the `QueryManager::rows()` docblock directly, not only in this design document, because it is the single most likely "why did my code throw a weird connection error" surprise a consumer new to streaming APIs will hit.

---

## 10. Benchmarking Approach

**Micro-benchmarks:** a `tools/benchmarks/` suite (using `phpbench/phpbench`, run manually and in a dedicated, non-blocking CI job rather than the PR-blocking gate from `20-testing.md` §11, since benchmark results are noisy on shared CI runners and are about trend-watching, not pass/fail) covers:
- Platform SQL-generation hot paths (`quoteIdentifier()`, `applyPagination()`, DDL rendering) — pure-PHP, no I/O, meaningful nanosecond/microsecond-level comparisons across commits.
- `MetadataFactory` hydration throughput (rows/second) for large fixture row sets.
- `PreparedStatementCache` hit-rate and overhead under realistic access patterns.

**Macro/regression benchmarks:** a scheduled (not per-PR) job runs the "big schema" scenario (§11) end-to-end against a Testcontainers instance and records wall-clock time and peak memory for `describeAllTables()` across a 1,000-table and 5,000-table fixture, publishing results to a tracked dashboard/log (`benchmark-history.json` committed per run, or an external time-series store if the project later adopts one) so a regression (e.g., a future change accidentally reintroducing an N+1 pattern into a batch path) shows up as a step-change in the trend line rather than being caught only by an engineer noticing subjective slowness.

**Guarding against regressions concretely:** the memory-delta assertion technique described in `20-testing.md` §9 (streamed vs. eager peak-memory comparison) is itself a regression guard, run in the standard Integration tier — it is not only a one-time proof that streaming works, but a standing assertion that stays in the suite and fails loudly if a future change accidentally makes a "streaming" method eager again.

---

## 11. Big-Schema Scenarios (Thousands of Tables)

A schema with several thousand tables (multi-tenant SaaS databases with per-tenant schemas, or long-lived enterprise MSSQL/Oracle instances, are the realistic sources of this scale) stresses exactly the mechanisms above simultaneously:

| Concern at scale | Mechanism that copes |
|---|---|
| Listing table names | `LazyCollection` (§2) — materializes once, O(1) memory beyond the list itself; no per-table round trip |
| Getting full structure for all tables | Batch introspection (§7) — 3-4 queries total, not 3-4×N |
| Iterating and processing each table's data | `\Generator`-based streaming (§3) — memory bounded by one row/table's data at a time, not the whole schema's data |
| Repeated re-introspection within a short session (e.g., an AI agent reasoning over the schema across many turns) | Opt-in metadata cache (§4) — avoids re-issuing the same batch introspection query on every turn |
| A consumer accidentally requesting an eager, eagerly-materialized view of everything | Row/item caps (§8) — fails loudly and cheaply rather than exhausting memory silently |
| Paginating through a large `listTables()`-style result in a UI | Keyset pagination (§6) applies to metadata listing too, not only data rows, when a consumer needs to page through table lists rather than materialize the `LazyCollection` fully |

**Concretely benchmarked target (tracked per §10):** `describeAllTables()` against a 5,000-table fixture (generated via `tests/Fixtures/LargeSchemaGenerator.php`, `20-testing.md` §10) completes in low-single-digit seconds against a local Testcontainers MySQL instance, with peak memory proportional to the *metadata* volume (thousands of small DTOs) rather than to any table's *data* volume — data rows are never touched by an introspection-only call, a distinction worth stating because it is easy to accidentally couple the two if a bulk-introspection implementation naively did something like `SELECT * FROM information_schema.columns` without also being careful that no code path anywhere in that call graph touches actual table row data.

---

## 12. Where Caching Must NOT Be Used

Metadata caching (§4) is opt-in and TTL-bounded, but even where enabled, it is **never** applied to:

1. **Actual query/data results** (`Query::rows()`, `18-public-api.md` §3.6) — caching arbitrary application data rows is an application-level concern with application-specific invalidation semantics SQLCraft cannot know (a consumer's own cache-aside pattern, using their own PSR-16/PSR-6 wiring around their own query calls, is the correct layer for this — not something SQLCraft does on a consumer's behalf inside `QueryManager`).
2. **Transaction-scoped reads** — any introspection or query performed *inside* an open transaction (`18-public-api.md` §3.7) always bypasses the metadata cache entirely, because a transaction may be about to `ALTER` the very object being introspected, and a stale cache read inside a transaction that is actively mutating schema is a correctness bug, not a performance win. `MetadataService` checks `ConnectionInterface::inTransaction()` (07 §5's interface sketch) and forces a live fetch whenever true, regardless of cache configuration.
3. **Capability resolution results tied to a specific live connection's runtime state** that isn't purely a function of platform+version (09 §4's static-matrix design already avoids this in the common case, but any future `$connection`-parameterized dynamic refinement mentioned in 09 §4, e.g. reading `@@innodb_compression_level`, is explicitly excluded from caching — that kind of runtime server-configuration-dependent check must always be live).
4. **Privilege/security checks** (`SecurityGuard`, 07 §10) — a cached "user has permission" result that outlives an actual `REVOKE` executed by another session is a security correctness bug, not merely a staleness inconvenience; `SecurityGuard` never consults the metadata cache and is architecturally excluded from ever being wired to one (there is no `CacheInterface` parameter anywhere in `SecurityGuardInterface`'s construction path).

---

## 13. Tradeoffs Summary

| Decision | Memory | Latency | Correctness risk | Chosen because |
|---|---|---|---|---|
| Generators/streaming as the default for data rows (§3) | Optimal (constant) | Slightly higher per-row overhead than a tight buffered loop, negligible in practice | None | Unbounded consumer input sizes make eager the only realistic risk |
| `LazyCollection` for metadata (§2) | Deferred, not eliminated | Pay-on-first-touch | None | Metadata sets are bounded (§11), unlike data rows |
| Opt-in metadata cache (§4) | Extra cache-backend memory (consumer's problem, not SQLCraft's) | Large win for repeated introspection | Staleness if a mutation bypasses `DdlManager` (e.g., another process's raw `ALTER TABLE`) — mitigated by short default TTL, not eliminated | Consumer explicitly opts in and controls TTL, understanding this tradeoff |
| Approximate counts as `paginate()` default (§6) | N/A | Fast | Count may be off by some percentage on heavily-written InnoDB tables between statistics refreshes | Exact `COUNT(*)` cost is the worse default for the common case (rendering "about N results") |
| Unbuffered queries for streaming (§9) | Optimal client-side | Comparable to buffered for the full scan; enables partial-consumption early-exit savings | Same-connection query interleaving breaks — documented, mitigated, not silently prevented | The alternative (buffered-by-default) reintroduces the exact memory problem streaming exists to solve |
| Batch introspection as the bulk-path default (§7) | Slightly higher single-query result size (all tables' columns at once) vs. many small queries | Large win at scale (§11); at very small scale (1-2 tables) marginally more overhead than direct per-table queries | None | N+1 avoidance dominates; small-scale overhead is negligible and `describeTable()` remains the ergonomic single-table entry point |
