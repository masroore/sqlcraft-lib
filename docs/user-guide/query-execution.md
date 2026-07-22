# Query Execution

SQLCraft separates query building from query execution. Raw SQL runs through `QueryExecutorInterface`; structured queries use immutable builders (`SelectQuery`, `InsertQuery`, `UpdateQuery`, `DeleteQuery`). Both paths guarantee that values are never interpolated into SQL strings.

---

## Raw Query Execution

### `$db->query()` — SELECT

Returns `ResultInterface`, which is iterable and countable.

```php
$result = $db->query(
    'SELECT id, email, created_at FROM users WHERE status = :status',
    ['status' => 'active'],
);

// Fetch all rows as associative arrays
$rows = $result->fetchAll(); // list<array<string, mixed>>

// Iterate row by row (streaming)
foreach ($result as $row) {
    echo $row['email'] . PHP_EOL;
}

// Fetch single row
$row = $result->fetchAssoc(); // array<string, mixed>|null

// Fetch a single column
$ids = $result->fetchColumn(0); // list<mixed>

// Column metadata
foreach ($result->getColumns() as $col) {
    echo $col->name . ' (' . $col->nativeType . ')' . PHP_EOL;
}
```

### `QueryExecutorInterface::execute()` — DML

Use `execute()` for `INSERT`, `UPDATE`, `DELETE`, and any statement that does not return rows.

```php
$connection = $db->connection();
// Direct access to the executor is available through DatabaseSession
// For DML, use executeBuilder() or the connection directly:
$result = $connection->execute(
    'UPDATE users SET last_login = NOW() WHERE id = :id',
    ['id' => 42],
);

echo $result->affectedRows; // int
echo $result->lastInsertId; // string|int|false
echo $result->elapsedMs;    // float
```

---

## Positional and Named Parameter Binding

Both `?` positional and `:name` named placeholders are supported. Values are always bound through PDO prepared statements — they are never string-interpolated.

```php
// Named parameters
$db->query('SELECT * FROM orders WHERE status = :s AND total > :min', [
    'status' => 'pending',
    'min'    => 100.0,
]);

// Positional parameters
$db->query('SELECT * FROM orders WHERE status = ? AND total > ?', [
    'pending',
    100.0,
]);
```

Do not mix named and positional in the same statement.

---

## Streaming vs Buffered Results

By default `$db->query()` returns a **streaming** (unbuffered) result. Rows are fetched from the server one at a time, keeping memory usage flat for large result sets.

```php
// Streaming (default) — row-by-row, low memory
$result = $db->query('SELECT * FROM large_table');
echo $result->isStreaming(); // true

foreach ($result as $row) {
    process($row);
}
```

Pass `buffered: true` on the underlying `ConnectionInterface::query()` to load all rows into memory upfront. This is required when you need `count()` or `seek()` on the result, or when you need to run another query on the same connection before consuming the result.

```php
$result = $db->connection()->query($sql, $params, streaming: false);
echo count($result); // available only on buffered results
$result->seek(10);
```

### Memory Model for Large Result Sets

For tables with millions of rows:

1. Use streaming iteration with `foreach` — do not call `fetchAll()`.
2. Process and discard each row inside the loop.
3. Do not hold references to processed rows.
4. If you need to JOIN in PHP, accumulate only keys, not full rows.

```php
$result = $db->query('SELECT id, payload FROM events ORDER BY id');
foreach ($result as $row) {
    dispatch(Event::fromRow($row)); // process and discard
}
```

---

## `SelectQuery` Builder

`SelectQuery` is an immutable value object. Build it up with `withWhere()` and `withOrderBy()`.

```php
use SQLCraft\Query\SelectQuery;
use SQLCraft\Query\WhereCondition;
use SQLCraft\Query\OrderByClause;
use SQLCraft\Query\ColumnSelection;
use SQLCraft\ValueObjects\QualifiedName;
use SQLCraft\ValueObjects\Identifier;

$query = new SelectQuery(
    table: new QualifiedName(new Identifier('orders')),
    columns: [new ColumnSelection('id'), new ColumnSelection('total')],
    distinct: false,
    limit: 50,
    offset: 0,
);

$query = $query
    ->withWhere(
        new WhereCondition(new Identifier('status'), '=', 'pending'),
        new WhereCondition(new Identifier('total'), '>=', 100),
    )
    ->withOrderBy(
        new OrderByClause('created_at', descending: true),
    );
```

`SelectQuery` does not execute itself. Pass it to `Paginator` or render it manually.

---

## `WhereCondition` and Operator Allowlisting

`WhereCondition` validates the operator at construction time. Operators must match the pattern `[A-Z][A-Z0-9]*(?: [A-Z0-9]+)*` (word operators like `IS NULL`, `NOT LIKE`) or consist entirely of comparison symbols (`=`, `!=`, `<`, `>`, `<=`, `>=`, `<>`).

```php
use SQLCraft\Query\WhereCondition;
use SQLCraft\ValueObjects\Identifier;

// Allowed operators
new WhereCondition(new Identifier('name'),   '=',        'Alice');
new WhereCondition(new Identifier('age'),    '>=',       18);
new WhereCondition(new Identifier('status'), 'IN',       ['active', 'trial']);
new WhereCondition(new Identifier('email'),  'LIKE',     '%@example.com');
new WhereCondition(new Identifier('deleted'),'IS NULL',  null);
new WhereCondition(new Identifier('score'),  'BETWEEN',  [1, 100]);

// Rejected — throws InvalidArgumentException
new WhereCondition(new Identifier('col'), '; DROP TABLE users --', 'x');
```

All values are bound as parameters — the operator is the only user-supplied string that enters the SQL template, and it is allowlisted.

---

## Pagination

### `PaginationParams`

```php
use SQLCraft\Query\PaginationParams;

$params = new PaginationParams(page: 3, limit: 25);
echo $params->offset(); // 50
```

### `Paginator` and `Page`

```php
use SQLCraft\Query\Paginator;
use SQLCraft\Query\SelectQueryRenderer;

$paginator = new Paginator(
    executor: $queryExecutor,
    renderer: new SelectQueryRenderer($db->connection()->getPlatform()),
    maximumLimit: 1000,
);

$page = $paginator->paginate($db->connection(), $query, $params);

// Page fields:
$page->rows;        // list<array<string, mixed>>
$page->params;      // PaginationParams (page, limit)
$page->totalRows;   // ?int — null if unknown
$page->totalApprox; // bool — true when total comes from table statistics
$page->hasMore;     // bool
$page->totalPages(); // ?int
```

When no `WHERE` clause is present and a `TableStatusProviderInterface` is wired in, the paginator uses approximate row counts from engine statistics instead of running a `COUNT(*)` query, making first-page loads fast on large tables. When a `WHERE` clause is present, an exact `COUNT(*)` is executed.

---

## DML Builders

### `InsertQuery`

```php
use SQLCraft\Query\InsertQuery;
use SQLCraft\ValueObjects\QualifiedName;
use SQLCraft\ValueObjects\Identifier;

// Build from a key-value row
$insert = InsertQuery::fromRow(
    new QualifiedName(new Identifier('users')),
    ['email' => 'alice@example.com', 'name' => 'Alice'],
);

// Multi-row insert
$insert = new InsertQuery(
    table: new QualifiedName(new Identifier('users')),
    columns: ['email', 'name'],
);
$insert = $insert->values(['bob@example.com', 'Bob']);
$insert = $insert->values(['carol@example.com', 'Carol']);

$result = $db->executeBuilder($insert);
echo $result->lastInsertId;
```

### `UpdateQuery`

```php
use SQLCraft\Query\UpdateQuery;
use SQLCraft\Query\WhereCondition;
use SQLCraft\ValueObjects\Identifier;

$update = new UpdateQuery(
    table: new QualifiedName(new Identifier('users')),
    assignments: ['status' => 'inactive'],
);
$update = $update
    ->set('updated_at', new \DateTimeImmutable())
    ->withWhere(new WhereCondition(new Identifier('id'), '=', 42));

$result = $db->executeBuilder($update);
echo $result->affectedRows;
```

### `DeleteQuery`

```php
use SQLCraft\Query\DeleteQuery;

$delete = new DeleteQuery(
    table: new QualifiedName(new Identifier('sessions')),
);
$delete = $delete->withWhere(
    new WhereCondition(new Identifier('expires_at'), '<', new \DateTimeImmutable()),
);

$result = $db->executeBuilder($delete);
```

### `executeBuilder()`

`DatabaseSession::executeBuilder()` accepts `InsertQuery`, `UpdateQuery`, or `DeleteQuery`, renders the appropriate SQL for the current platform, and returns `ExecutionResult`.

```php
$result = $db->executeBuilder($insert);
// ExecutionResult fields:
$result->affectedRows; // int
$result->lastInsertId; // string
$result->elapsedMs;    // float
$result->sql;          // string — rendered SQL (no values)
```

---

## `TransactionManager`

`TransactionManager` provides safe, nestable transaction handling via savepoints.

### Closure-Based (Recommended)

```php
use SQLCraft\Connection\TransactionManager;

$txManager = new TransactionManager();

$result = $txManager->transactional(
    $db->connection(),
    function (\SQLCraft\Contracts\Connection\ConnectionInterface $conn) use ($db): string {
        $db->executeBuilder(InsertQuery::fromRow(
            new QualifiedName(new Identifier('orders')),
            ['user_id' => 1, 'total' => 99.99],
        ));
        $db->executeBuilder(InsertQuery::fromRow(
            new QualifiedName(new Identifier('order_items')),
            ['order_id' => (int) $conn->lastInsertId(), 'sku' => 'WIDGET-1'],
        ));
        return 'ok';
    },
);
```

If the callback throws, the transaction is rolled back automatically and the exception re-thrown.

### Manual Begin / Commit / Rollback

```php
$tx = $txManager->begin($db->connection());
// $tx is a Transaction object

try {
    // ... queries ...
    $tx->commit();
} catch (\Throwable $e) {
    if ($tx->isActive()) {
        $tx->rollback();
    }
    throw $e;
}
```

### Savepoint Nesting

If a transaction is already active when `begin()` is called, `TransactionManager` issues a `SAVEPOINT sp_{random}` instead of a nested `BEGIN`. Committing releases the savepoint; rolling back issues `ROLLBACK TO SAVEPOINT`.

```php
// Outer transaction
$outer = $txManager->begin($db->connection());

// This creates a savepoint, not a nested BEGIN
$inner = $txManager->begin($db->connection());

// Rollback inner only
$inner->rollback(); // ROLLBACK TO SAVEPOINT sp_abc123

$outer->commit();   // COMMIT
```

---

## `BatchExecutor` for Multi-Statement Scripts

`BatchExecutor` runs a list of SQL statements sequentially and returns per-statement results including any errors.

```php
use SQLCraft\Execution\BatchExecutor;
use SQLCraft\Contracts\Execution\StatementBatch;

$executor = new BatchExecutor($queryExecutor);

$batch = new StatementBatch([
    'CREATE TABLE tmp_a (id INT)',
    'INSERT INTO tmp_a VALUES (1)',
    'INSERT INTO tmp_a VALUES (2)',
]);

$results = $executor->executeBatch($db->connection(), $batch, stopOnError: false);

foreach ($results as $r) {
    if ($r->error !== null) {
        echo 'Error at statement ' . $r->index . ': ' . $r->error->getMessage() . PHP_EOL;
    }
}
```

---

## `StatementSplitter` and DELIMITER Handling

When executing SQL scripts that contain stored procedures, triggers, or functions, the `DELIMITER` directive separates statements that themselves contain semicolons.

```php
use SQLCraft\Query\StatementSplitter;

$splitter = new StatementSplitter();
$script = <<<'SQL'
    CREATE TABLE a (id INT);
    DELIMITER $$
    CREATE TRIGGER trg BEFORE INSERT ON a
    FOR EACH ROW BEGIN
        SET NEW.id = NEW.id + 1;
    END$$
    DELIMITER ;
    INSERT INTO a VALUES (1);
SQL;

$batch = $splitter->split($script);
// $batch->statements is a list<string> with 3 elements:
// "CREATE TABLE a (id INT)"
// "CREATE TRIGGER trg ..."
// "INSERT INTO a VALUES (1)"
```

`StatementSplitter` also implements `StreamingStatementSplitterInterface`, so it can process scripts line by line from a stream without loading the entire file into memory.

---

## Query History

Plug in a `QueryHistoryInterface` to record queries for debugging or auditing.

### `NullQueryHistory` (default)

Discards all records — zero overhead.

### `InMemoryQueryHistory`

Keeps an in-memory ring buffer of recent queries.

```php
use SQLCraft\Execution\InMemoryQueryHistory;

$history = new InMemoryQueryHistory(maxEntries: 200);
// Pass to QueryExecutor constructor
```

### `CallbackQueryHistory`

Delegates to a callable, useful for forwarding to a PSR-3 logger:

```php
use SQLCraft\Execution\CallbackQueryHistory;

$history = new CallbackQueryHistory(function (QueryHistoryEntry $entry): void {
    $logger->debug($entry->sql, ['elapsed_ms' => $entry->elapsedMs]);
});
```

Query history entries carry: `sql`, `startedAt`, `elapsedMs`, `success`, and `errorMessage`.

---

## Events

`QueryExecutor` dispatches the following events:

| Event | When |
|---|---|
| `BeforeQueryExecuted` | Before every query; cancellable |
| `AfterQueryExecuted` | After successful query |
| `QueryFailedEvent` | On exception |
| `SlowQueryDetectedEvent` | When elapsed time exceeds `slowQueryThresholdMs` (default 1000 ms) |

```php
use SQLCraft\Events\SlowQueryDetectedEvent;

// With a PSR-14 event dispatcher:
$dispatcher->addListener(SlowQueryDetectedEvent::class, function (SlowQueryDetectedEvent $e): void {
    $logger->warning('Slow query', ['sql' => $e->sql, 'ms' => $e->elapsedMs]);
});
```

---

## Security

SQLCraft never interpolates user-supplied values into SQL strings. All values flow through PDO's parameter binding. The only user-supplied strings that enter SQL templates are:

- Table and column names, which must be wrapped in `Identifier` (validated against empty/null-byte) and are quoted by the platform's `quoteIdentifier()`.
- The `WhereCondition` operator, which is validated against an allowlist pattern at construction.

Never construct raw SQL by concatenating user input:

```php
// UNSAFE — never do this
$sql = "SELECT * FROM users WHERE name = '" . $_GET['name'] . "'";

// Safe — use parameter binding
$result = $db->query('SELECT * FROM users WHERE name = :name', ['name' => $_GET['name']]);
```

---

## Examples by Platform

### MySQL — Streaming Large Table

```php
$result = $db->connection()->query(
    'SELECT id, payload FROM events WHERE processed = 0 ORDER BY id',
    [],
    streaming: true,
);
foreach ($result as $row) {
    processEvent($row['id'], $row['payload']);
}
```

### PostgreSQL — Returning Clause

```php
$result = $db->query(
    'INSERT INTO orders (user_id, total) VALUES (:uid, :total) RETURNING id, created_at',
    ['uid' => 1, 'total' => 49.99],
);
$row = $result->fetchAssoc();
echo $row['id'];
```

### SQLite — In-Memory Testing

```php
use SQLCraft\Enums\DatabaseDriver;

$factory = new SQLCraftFactory();
$db = $factory->session(new ConnectionParameters(
    database: ':memory:',
    driver: DatabaseDriver::SQLite,
));

$db->ddl()->execute($db->connection(), $createTableBuilder);
$result = $db->query('SELECT count(*) AS n FROM users');
```

### SQL Server — Paginated Query

```php
$query = new SelectQuery(
    table: new QualifiedName(new Identifier('orders'), new Identifier('dbo')),
    limit: 20,
    offset: 40,
);
$query = $query->withOrderBy(new OrderByClause('id'));
// SQL Server renders this as OFFSET 40 ROWS FETCH NEXT 20 ROWS ONLY
```

---

## Best Practices

- Use `executeBuilder()` for DML instead of raw `$db->query()` — it keeps values out of SQL and produces platform-appropriate SQL automatically.
- Default to streaming results; switch to buffered only when you need `count()` or `seek()`.
- Always wrap multi-statement operations in `TransactionManager::transactional()` — it handles rollback automatically.
- Set a `slowQueryThresholdMs` on `QueryExecutor` in production to surface N+1 and missing-index issues via `SlowQueryDetectedEvent`.
- Use `InMemoryQueryHistory` during development and switch to `NullQueryHistory` in production to avoid unbounded memory growth.
- Keep `BatchExecutor` for script execution; use `executeBuilder()` for application DML — do not mix.
