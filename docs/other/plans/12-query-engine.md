# 12 — Query Engine

> **Status:** Design draft
> **Scope note:** Oracle-specific details in this document are future-version design notes; Oracle is deferred and not supported by v1.
> **Scope:** `SQLCraft\Query` and `SQLCraft\Execution` namespaces — `QueryExecutor`, streaming vs buffered results, multi-statement/multi-resultset execution, `TransactionManager`, savepoints, `Paginator`, `SelectQuery` builder, `ExplainService`, warnings, query timeout, `QueryHistory`
> **Depends on:** 05-domain-model.md (DTOs, exception hierarchy), 08-driver-architecture.md (PlatformInterface, PaginationInterface), 09-capability-model.md (Capability), 10-connection-layer.md (ConnectionInterface, ResultInterface, Transaction)
> **Namespace root:** `SQLCraft\Query`, `SQLCraft\Execution`

---

## 1. Design Goals

The query engine layer sits above the raw `ConnectionInterface` and provides:

1. **Safe execution by default** — prepared statements with bound parameters everywhere; never raw string interpolation of user input.
2. **Streaming-first for large data** — generator-based row iteration as the default API for SELECT; buffered as an explicit opt-in.
3. **Multi-statement support** — iterating multiple result sets from one batch, DELIMITER handling for routine bodies.
4. **Structured transaction management** — nested transactions via savepoints, isolation levels, `transactional()` helper.
5. **Platform-aware pagination** — `Paginator` delegates LIMIT/OFFSET, TOP, rownum strategies to the platform.
6. **Composable query building** — a `SelectQuery` VO representing a browse query (columns, filters, order, pagination) that the platform renders to SQL.
7. **Observability** — `ExplainService`, warnings retrieval, per-engine `slowQuery` timeout, `QueryHistory`.

---

## 2. `QueryExecutor`

`QueryExecutor` is the primary service for executing SQL. It wraps `ConnectionInterface` and adds safety rails: bound parameters are validated, results are wrapped in `ExecutionResult` or `ResultInterface`, and events are dispatched (see 16-events.md).

```php
namespace SQLCraft\Contracts\Execution;

use SQLCraft\Contracts\Connection\{ConnectionInterface, ResultInterface};
use SQLCraft\DTO\ExecutionResult;

interface QueryExecutorInterface
{
    /**
     * Execute a data-modifying statement (INSERT/UPDATE/DELETE/DDL).
     * Always uses prepared statements when $params is non-empty.
     *
     * @param array<string|int, mixed> $params
     */
    public function execute(
        ConnectionInterface $conn,
        string              $sql,
        array               $params = [],
    ): ExecutionResult;

    /**
     * Execute a SELECT statement and return a streaming generator.
     * Default: streaming (constant memory). Pass $buffered=true to materialize.
     *
     * @param array<string|int, mixed> $params
     */
    public function query(
        ConnectionInterface $conn,
        string              $sql,
        array               $params   = [],
        bool                $buffered = false,
    ): ResultInterface;

    /**
     * Execute a DDL statement. Returns void; DDL produces no result set.
     * Always executes in autocommit context unless $conn is already in a transaction.
     *
     * @param array<string|int, mixed> $params  Usually empty for DDL; available for parameterized DDL (rare)
     */
    public function executeDdl(
        ConnectionInterface $conn,
        string              $sql,
        array               $params = [],
    ): void;
}
```

```php
namespace SQLCraft\DTO;

final readonly class ExecutionResult
{
    public function __construct(
        public readonly int        $affectedRows,
        public readonly string|int $lastInsertId,  // '' or 0 when not applicable
        public readonly float      $elapsedMs,
        public readonly string     $sql,            // for audit/history (params redacted)
    ) {}
}
```

**Design decision — why separate `execute()` and `executeDdl()`:** DDL statements (CREATE TABLE, ALTER TABLE, DROP INDEX) have different semantics in some engines. MySQL auto-commits DDL even inside transactions; PostgreSQL allows transactional DDL. The explicit `executeDdl()` method lets the executor emit a `DdlExecutedEvent` (see 16-events.md) and invalidate the metadata cache (see 11-schema-services.md §5) without ambiguity about whether the SQL is DDL.

---

## 3. Streaming vs Buffered Results

The default is streaming. Adminer's `query($sql, $unbuffered)` boolean is replaced by the more descriptive `$buffered` flag (inverted polarity — buffered is the non-default).

| Mode | Memory | Cursor | Use case | PHP generator? |
|------|--------|--------|----------|----------------|
| Streaming (default) | O(1) per row | Forward-only | Large exports, browse with row cap, AI agent ingestion | Yes |
| Buffered | O(rows) | Random-seek | Small result sets, column metadata needed before row iteration | No; array-backed |

```php
// Streaming — constant memory regardless of result size
$result = $executor->query($conn, 'SELECT * FROM large_table WHERE status = ?', ['active']);
foreach ($result as $row) {
    // $row is array<string, mixed>; only one row in memory at a time
    $pipeline->process($row);
}

// Buffered — all rows in memory; seek() is available
$result = $executor->query($conn, 'SELECT id, name FROM small_config_table', buffered: true);
$rows   = $result->fetchAll(); // array<int, array<string, mixed>>
$result->seek(2);              // jump to row index 2
```

`ResultInterface` (10-connection-layer.md §8) exposes `isStreaming()` and throws `StreamingResultException` if `seek()` or `count()` is called on a streaming result.

---

## 4. Multi-Statement / Multi-Resultset Execution

Adminer's SQL command panel supports multi-statement input with a custom `DELIMITER` for routine bodies. SQLCraft models this via `StatementBatch`.

### 4.1 `StatementBatch`

```php
namespace SQLCraft\Execution;

final readonly class StatementBatch
{
    /** @param list<string> $statements individual SQL statements after splitting */
    public function __construct(public readonly array $statements) {}
}
```

```php
namespace SQLCraft\Contracts\Execution;

interface StatementSplitterInterface
{
    /**
     * Split a multi-statement SQL string respecting a custom DELIMITER.
     * Handles quoted strings, block comments, line comments.
     * Default delimiter: ';'. Custom: any string (e.g., '$$' for PgSQL routine bodies).
     *
     * @return StatementBatch
     */
    public function split(string $sql, string $delimiter = ';'): StatementBatch;
}
```

### 4.2 `BatchExecutor`

```php
interface BatchExecutorInterface
{
    /**
     * Execute all statements in the batch sequentially.
     * Yields one BatchStatementResult per statement as a generator.
     *
     * $stopOnError = true (default): exception stops iteration.
     * $stopOnError = false: collect all errors, continue to end.
     *
     * @return \Generator<BatchStatementResult>
     */
    public function executeBatch(
        ConnectionInterface $conn,
        StatementBatch      $batch,
        bool                $stopOnError = true,
    ): \Generator;
}
```

```php
final readonly class BatchStatementResult
{
    public function __construct(
        public readonly int              $index,
        public readonly string           $sql,
        public readonly ?ExecutionResult $result,   // null if SELECT
        public readonly ?ResultInterface $rows,     // null if non-SELECT
        public readonly float            $elapsedMs,
        public readonly ?\Throwable      $error,    // null if success
    ) {}
}
```

**Multi-resultset iteration:** Some statements (e.g., `CALL stored_procedure()` on MySQL which returns multiple result sets, or batched queries) return more than one result set. Adminer iterates via `next_result()` / `store_result()` on its `PdoResult`. `BatchExecutor` handles this internally: after each statement, if the PDO driver indicates more results (via `PDOStatement::nextRowset()`), additional `ResultInterface` objects are yielded before moving to the next batch statement.

**DELIMITER handling:** When the user's SQL contains `DELIMITER $$`, the splitter recognizes the directive, switches the active delimiter to `$$`, and uses it until the next `DELIMITER` reset. The `$$` lines themselves are not sent to the database. This matches Adminer's behavior exactly.

---

## 5. `TransactionManager`

### 5.1 Core Interface

```php
namespace SQLCraft\Contracts\Execution;

interface TransactionManagerInterface
{
    /**
     * Begin a transaction and return a Transaction handle.
     * If already in a transaction, creates a savepoint instead (nested transaction emulation).
     */
    public function begin(
        ConnectionInterface $conn,
        string              $isolationLevel = '',
    ): Transaction;

    /**
     * Execute $callback inside a transaction.
     * Commits on success; rolls back on exception; re-throws the exception.
     * Handles nesting via savepoints automatically.
     *
     * @template T
     * @param callable(ConnectionInterface): T $callback
     * @return T
     */
    public function transactional(ConnectionInterface $conn, callable $callback): mixed;
}
```

### 5.2 Nested Transactions via Savepoints

PHP's PDO does not support real nested `BEGIN TRANSACTION` blocks. When `begin()` is called while already in a transaction, `TransactionManager` creates a savepoint:

```php
public function begin(ConnectionInterface $conn, string $isolationLevel = ''): Transaction
{
    if ($conn->inTransaction()) {
        $name = 'sp_' . bin2hex(random_bytes(6));
        $conn->execute("SAVEPOINT {$name}");
        return new Transaction($conn, savepointName: $name);
    }

    if ($isolationLevel !== '') {
        $this->setIsolationLevel($conn, $isolationLevel);
    }
    $conn->beginTransaction($isolationLevel);
    return new Transaction($conn);
}
```

`Transaction::commit()` for a savepoint-backed transaction calls `RELEASE SAVEPOINT`; `Transaction::rollback()` calls `ROLLBACK TO SAVEPOINT`. (See 10-connection-layer.md §10 for the `Transaction` class.)

**Isolation levels:** The standard SQL levels (`READ UNCOMMITTED`, `READ COMMITTED`, `REPEATABLE READ`, `SERIALIZABLE`) are supported where the engine does (MySQL, PgSQL, MSSQL). SQLite supports `DEFERRED`, `IMMEDIATE`, `EXCLUSIVE`. Oracle uses `READ COMMITTED` and `SERIALIZABLE` only. The `TransactionManager` validates the requested level against `CapabilitySet` before applying it.

### 5.3 `transactional()` Helper

```php
// Usage
$result = $transactionManager->transactional($conn, function (ConnectionInterface $conn) use ($data) {
    $executor->execute($conn, 'INSERT INTO orders (...) VALUES (?)', [$data['order']]);
    $executor->execute($conn, 'UPDATE stock SET qty = qty - ? WHERE id = ?', [$data['qty'], $data['itemId']]);
    return $conn->lastInsertId();
});
// $result = new order id; transaction committed
// If any execute() threw, transaction rolled back and exception re-thrown
```

Nesting `transactional()` inside another `transactional()` silently uses savepoints. This matches the behavior developers expect from frameworks like Doctrine.

---

## 6. `Paginator` and `Page`

### 6.1 `PaginationParams` VO

```php
namespace SQLCraft\Query;

final readonly class PaginationParams
{
    public function __construct(
        public readonly int  $page,     // 1-based
        public readonly int  $limit,    // rows per page
    ) {
        if ($page < 1)  throw new \InvalidArgumentException('Page must be >= 1');
        if ($limit < 1) throw new \InvalidArgumentException('Limit must be >= 1');
    }

    public function offset(): int { return ($this->page - 1) * $this->limit; }
}
```

### 6.2 `Page` DTO

```php
final readonly class Page
{
    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(
        public readonly array             $rows,
        public readonly PaginationParams  $params,
        public readonly ?int              $totalRows,     // null = unknown (InnoDB approx)
        public readonly bool              $totalApprox,   // true = rows is estimate
        public readonly bool              $hasMore,       // true = next page exists
    ) {}

    public function totalPages(): ?int
    {
        return $this->totalRows !== null
            ? (int) ceil($this->totalRows / $this->params->limit)
            : null;
    }
}
```

### 6.3 Count Strategy

Adminer uses `SQL_CALC_FOUND_ROWS` on MySQL (deprecated in 8.0.17, removed in future) for pagination counts, and `COUNT(*)` on others. SQLCraft uses a more robust strategy:

| Engine | Row-count strategy |
|--------|-------------------|
| MySQL / MariaDB | Separate `SELECT COUNT(*)` with same WHERE (avoids deprecated `SQL_CALC_FOUND_ROWS`); InnoDB `information_schema.TABLES.TABLE_ROWS` as approximation for no-WHERE count |
| PostgreSQL | `SELECT COUNT(*)` for filtered queries; `pg_class.reltuples` as approximation for full-table display |
| SQLite | `SELECT COUNT(*)` always |
| MSSQL | `SELECT COUNT(*)` |
| Oracle | `SELECT COUNT(*)` |

The `Paginator` requests two queries: the page data query (with LIMIT/OFFSET) and a count query. The count is cached within a request session (via `MetadataCacheInterface`) to avoid re-running it on every "next page" navigation.

**Approximate counts:** When `TableStatus::$rows` (from 11-schema-services.md) is available and the query has no WHERE clause, the paginator uses it as an approximation and sets `$page->totalApprox = true`. UI consumers can display "~50,000 rows" instead of running an expensive full COUNT.

### 6.4 `Paginator` Interface

```php
namespace SQLCraft\Contracts\Query;

interface PaginatorInterface
{
    public function paginate(
        ConnectionInterface $conn,
        SelectQuery         $query,
        PaginationParams    $params,
    ): Page;
}
```

The concrete `Paginator` calls `PaginationInterface::applyPagination()` (08-driver-architecture.md §3.2) on the platform to wrap the SELECT SQL. This handles MySQL LIMIT/OFFSET, MSSQL `OFFSET n ROWS FETCH NEXT m ROWS ONLY`, Oracle rownum subquery, etc.

---

## 7. `SelectQuery` Builder

`SelectQuery` is a **value object** representing a browse/select query structure. It is not a general-purpose query builder — it covers the browse use case (Adminer's `selectQueryBuild()`): column selection, WHERE conditions from a typed operator list, ORDER BY, GROUP BY, aggregate functions, and LIMIT.

```php
namespace SQLCraft\Query;

final readonly class SelectQuery
{
    /**
     * @param list<ColumnSelection>  $columns   [] = SELECT *
     * @param list<WhereCondition>   $where
     * @param list<OrderByClause>    $orderBy
     * @param list<string>           $groupBy   column names
     * @param bool                   $distinct
     * @param ?int                   $limit
     * @param ?int                   $offset
     */
    public function __construct(
        public readonly QualifiedName    $table,
        public readonly array            $columns  = [],
        public readonly array            $where    = [],
        public readonly array            $orderBy  = [],
        public readonly array            $groupBy  = [],
        public readonly bool             $distinct = false,
        public readonly ?int             $limit    = null,
        public readonly ?int             $offset   = null,
    ) {}

    public function withWhere(WhereCondition ...$conditions): self
    {
        return new self(..., where: [...$this->where, ...$conditions]);
    }

    public function withOrderBy(OrderByClause ...$clauses): self
    {
        return new self(..., orderBy: [...$this->orderBy, ...$clauses]);
    }
}
```

```php
final readonly class ColumnSelection
{
    public function __construct(
        public readonly Identifier  $column,
        public readonly ?string     $aggregateFunction = null, // 'COUNT', 'SUM', 'AVG', etc.
        public readonly ?Identifier $alias             = null,
    ) {}
}

final readonly class WhereCondition
{
    /**
     * @param string $operator — must come from PlatformInterface::getOperators(); validated before use
     */
    public function __construct(
        public readonly Identifier $column,
        public readonly string     $operator,
        public readonly mixed      $value,   // bound as parameter; never interpolated
    ) {}
}

final readonly class OrderByClause
{
    public function __construct(
        public readonly Identifier $column,
        public readonly bool       $descending = false,
    ) {}
}
```

**Operator allowlisting:** The `operator` field of `WhereCondition` must be validated against the platform's operator list before constructing a `WhereCondition`. The platform's `getOperators()` method returns the valid operators for that engine (e.g., MySQL: `=`, `!=`, `LIKE`, `REGEXP`, `IN`, `IS NULL`, `IS NOT NULL`, `BETWEEN`, etc.). Passing an unknown operator throws a `\InvalidArgumentException` at construction time. See 15-security.md §4 for the full operator allowlisting design.

### 7.1 `SelectQueryRenderer`

The `SelectQueryRenderer` converts a `SelectQuery` VO into SQL using the platform:

```php
namespace SQLCraft\Query;

final class SelectQueryRenderer
{
    public function __construct(private readonly PlatformInterface $platform) {}

    /**
     * @return array{sql: string, params: list<mixed>}
     */
    public function render(SelectQuery $query): array
    {
        $cols   = $this->renderColumns($query->columns);
        $from   = $this->platform->quoteIdentifier(new Identifier($query->table->object->name));
        $where  = $this->renderWhere($query->where);
        $order  = $this->renderOrderBy($query->orderBy);
        $group  = $this->renderGroupBy($query->groupBy);

        $sql = "SELECT {$cols} FROM {$from}";
        if ($query->distinct) $sql = "SELECT DISTINCT {$cols} FROM {$from}";
        if ($group)  $sql .= " GROUP BY {$group}";
        if ($where)  $sql .= " WHERE {$where['clause']}";
        if ($order)  $sql .= " ORDER BY {$order}";

        if ($query->limit !== null) {
            $sql = $this->platform->applyPagination($sql, $query->limit, $query->offset ?? 0);
        }

        return ['sql' => $sql, 'params' => $where['params'] ?? []];
    }
}
```

All values in WHERE conditions are collected as bound parameters — never interpolated.

---

## 8. `ExplainService`

Query execution plans are engine-specific. SQLCraft wraps EXPLAIN into a typed service:

```php
namespace SQLCraft\Contracts\Execution;

interface ExplainServiceInterface
{
    /**
     * Return the execution plan for a SELECT query.
     * Returns structured rows (the actual EXPLAIN output rows as typed DTOs).
     *
     * @return ExplainResult
     */
    public function explain(
        ConnectionInterface $conn,
        string              $sql,
        array               $params = [],
        bool                $analyze = false, // EXPLAIN ANALYZE (actually executes query)
    ): ExplainResult;
}
```

```php
final readonly class ExplainResult
{
    /**
     * @param list<array<string, mixed>> $rows  Raw EXPLAIN rows (engine-specific columns)
     * @param ?string                    $tree  Tree-format plan (PgSQL/MySQL 8 FORMAT=TREE)
     * @param ?array                     $json  JSON plan (MySQL EXPLAIN FORMAT=JSON / PgSQL)
     */
    public function __construct(
        public readonly string  $engine,
        public readonly array   $rows,
        public readonly ?string $tree = null,
        public readonly ?array  $json = null,
        public readonly float   $elapsedMs = 0.0, // only meaningful when $analyze=true
    ) {}
}
```

**Per-engine EXPLAIN SQL:**
| Engine | EXPLAIN form | ANALYZE support |
|--------|-------------|-----------------|
| MySQL 8+ | `EXPLAIN FORMAT=JSON`, `EXPLAIN ANALYZE` | Yes (8.0.18+) |
| MySQL 5.7 | `EXPLAIN` (tabular) | No |
| MariaDB | `EXPLAIN`, `EXPLAIN FORMAT=JSON` | `ANALYZE` as of 10.1 |
| PostgreSQL | `EXPLAIN (FORMAT JSON, ANALYZE, BUFFERS)` | Yes |
| SQLite | `EXPLAIN QUERY PLAN` | No (ANALYZE = runs query) |
| MSSQL | `SET SHOWPLAN_XML ON` / `SET STATISTICS IO ON` | Via STATISTICS |
| Oracle | `EXPLAIN PLAN FOR ...` + `DBMS_XPLAN.DISPLAY` | Via AUTOTRACE |

The platform's `IntrospectionDialectInterface` provides `getExplainSql(string $sql, bool $analyze): string`. The `ExplainService` uses this to build the correct EXPLAIN form per engine. Raw output rows are returned without attempting cross-engine normalization — plan shapes are too engine-specific to normalize usefully.

---

## 9. Warnings

After certain MySQL/MariaDB statements, `SHOW WARNINGS` returns non-fatal messages. SQLCraft surfaces these:

```php
interface WarningsProviderInterface
{
    /**
     * Retrieve warnings from the last executed statement.
     * Returns empty collection on engines where warnings are not supported.
     *
     * @return WarningCollection
     */
    public function getWarnings(ConnectionInterface $conn): WarningCollection;
}
```

```php
final readonly class QueryWarning
{
    public function __construct(
        public readonly string $level,   // 'Warning', 'Note', 'Error'
        public readonly int    $code,
        public readonly string $message,
    ) {}
}
```

Warnings are fetched lazily on demand — not automatically after every statement — to avoid the extra round-trip overhead in non-debug contexts. `BatchExecutor` collects warnings per statement when `$collectWarnings = true` is passed.

---

## 10. Query Timeout (slowQuery)

Adminer's `slowQuery(query, timeout)` method maps to per-engine statement timeout hints. SQLCraft models this as a timeout parameter on execution:

```php
interface QueryExecutorInterface
{
    /**
     * Execute a SELECT with a per-query timeout hint.
     * $timeoutMs = 0 means no timeout.
     * Returns null if the engine does not support per-query timeouts (Capability check).
     * Throws QueryTimeoutException if the timeout is exceeded.
     */
    public function queryWithTimeout(
        ConnectionInterface $conn,
        string              $sql,
        array               $params    = [],
        int                 $timeoutMs = 0,
    ): ?ResultInterface;
}
```

**Per-engine timeout mechanism:**

| Engine | Mechanism |
|--------|-----------|
| MySQL / MariaDB | `SET STATEMENT max_statement_time = N FOR SELECT ...` (MariaDB); `MAX_EXECUTION_TIME(N)` optimizer hint (MySQL 5.7.8+) |
| PostgreSQL | `SET LOCAL statement_timeout = 'Nms'` before the query |
| SQLite | `sqlite3_progress_handler` via PDO callback (limited) |
| MSSQL | `SET QUERY_GOVERNOR_COST_LIMIT` or `OPTION (QUERYTIMEOUT N)` |
| Oracle | `DBMS_STATEMENT.SET_TIMEOUT` / resource plan |

The platform's `IntrospectionDialectInterface` includes a `wrapWithTimeout(string $sql, int $ms): ?string` method that returns null if the engine does not support this, triggering `queryWithTimeout()` to also return null. The caller can choose to proceed without timeout or throw.

---

## 11. `QueryHistory`

Adminer stores a per-database query history in the browser session. SQLCraft models history as a storage-agnostic interface:

```php
namespace SQLCraft\Contracts\Execution;

interface QueryHistoryInterface
{
    /** Record an executed statement. */
    public function record(QueryHistoryEntry $entry): void;

    /**
     * Fetch recent entries for a database.
     * @param int $limit Max entries to return (default 100).
     * @return list<QueryHistoryEntry>
     */
    public function getRecent(string $database, int $limit = 100): array;

    /** Remove all history for a database. */
    public function clearDatabase(string $database): void;
}
```

```php
final readonly class QueryHistoryEntry
{
    public function __construct(
        public readonly string    $database,
        public readonly string    $sql,        // Full SQL (params redacted in sensitive mode)
        public readonly float     $elapsedMs,
        public readonly \DateTimeImmutable $executedAt,
        public readonly bool      $success,
        public readonly ?string   $errorMessage,
    ) {}
}
```

**Built-in implementations:**

| Class | Storage | Notes |
|-------|---------|-------|
| `NullQueryHistory` | No-op | Default; zero overhead |
| `InMemoryQueryHistory` | PHP array | Per-process; lost on restart; suitable for CLI tools |
| `CallbackQueryHistory` | Closure | Consumer injects write logic; wire to file, Redis, DB, etc. |

The `QueryExecutor` accepts an optional `QueryHistoryInterface`. When injected, all executions are recorded automatically. This replaces Adminer's session-based history (`$_SESSION['queries'][$database]`) with a clean, framework-independent abstraction.

---

## 12. Summary of Key Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Streaming default | `$buffered = false` by default | Memory safety; explicit opt-in for buffered |
| Multi-statement | `StatementBatch` + `BatchExecutor` generator | Streaming; per-statement timing and error reporting |
| Nested transactions | Savepoints | PDO doesn't support real nested transactions; savepoints are the standard emulation |
| `SQL_CALC_FOUND_ROWS` | Replaced with separate COUNT query | Deprecated in MySQL 8; separate COUNT works on all engines |
| Approx counts | `TableStatus::$rows` when available | Avoids slow COUNT(*) for display of large tables |
| SelectQuery | Immutable VO + renderer | Type-safe; no string concatenation of untrusted input; testable |
| Operator allowlist | Platform-supplied list validated at VO construction | Prevents operator injection; always engine-specific |
| EXPLAIN normalization | Not normalized; engine-specific rows returned | Plan shapes too different to normalize usefully without losing fidelity |
| QueryHistory | Interface + NullQueryHistory default | No forced storage dep; consumer chooses backend |
| Timeout | Platform-delegated `wrapWithTimeout` | Engine mechanisms vary too much for a generic approach |
