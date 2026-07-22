# Streaming and Performance

This guide covers memory-efficient data processing, streaming patterns, and performance optimization.

## Memory Model

SQLCraft uses **streaming by default** to minimize memory usage:

```php
// Streaming (default) - constant memory
$result = $db->query('SELECT * FROM huge_table');
foreach ($result as $row) {
    // Each row is fetched one at a time
    // Previous rows are garbage collected
    processRow($row);
}

// Buffered - loads all into memory
$result = $db->query('SELECT * FROM small_table', buffered: true);
$allRows = iterator_to_array($result);
```

## Streaming Queries

### Generator-Based Iteration

Results implement `IteratorAggregate` with generator backing:

```php
$result = $db->query('SELECT * FROM orders');

// Internally uses a generator
foreach ($result as $row) {
    // Memory footprint = ~1 row at a time
    echo $row['id'] . "\n";
}
```

### Large Result Sets

Process millions of rows with constant memory:

```php
$processed = 0;
$result = $db->query('SELECT * FROM transactions WHERE year = 2024');

foreach ($result as $row) {
    processTransaction($row);
    $processed++;
    
    if ($processed % 10000 === 0) {
        echo "Processed $processed rows\n";
        gc_collect_cycles(); // Optional: force GC
    }
}
```

### Memory Comparison

```php
// ❌ Bad - loads 1 million rows into memory (400+ MB)
$result = $db->query('SELECT * FROM huge_table', buffered: true);
$rows = iterator_to_array($result);

// ✅ Good - processes 1 million rows with ~1 KB memory
$result = $db->query('SELECT * FROM huge_table');
foreach ($result as $row) {
    // Process one row at a time
}
```

## Buffered Mode

Use buffered mode when you need:
- Multiple iterations over results
- `count()` before iteration
- Random access to rows
- Results that fit in memory

```php
$result = $db->query('SELECT * FROM users', buffered: true);

// Now you can iterate multiple times
foreach ($result as $row) { /* first pass */ }
foreach ($result as $row) { /* second pass */ }

// Or convert to array
$rows = iterator_to_array($result);
echo "Found " . count($rows) . " users\n";
```

## Chunked Processing

Process large datasets in chunks:

```php
function processInChunks(DatabaseSession $db, callable $processor, int $chunkSize = 1000): void
{
    $offset = 0;
    
    do {
        $result = $db->query(
            "SELECT * FROM orders LIMIT ? OFFSET ?",
            [$chunkSize, $offset]
        );
        
        $count = 0;
        foreach ($result as $row) {
            $processor($row);
            $count++;
        }
        
        $offset += $chunkSize;
        
    } while ($count === $chunkSize);
}

// Usage
processInChunks($db, function ($row) {
    sendEmail($row['customer_email'], $row);
}, chunkSize: 500);
```

## Export Streaming

Exports stream data without loading the full result set:

```php
// Stream 10 GB table to file with constant memory
$db->export()
    ->table('huge_table')
    ->toFile('/tmp/huge_table.csv', format: 'csv');

// Memory usage: ~10 MB regardless of table size
```

### Custom Stream Destination

```php
$handle = fopen('php://output', 'w');

$db->export()
    ->table('products')
    ->toStream($handle, format: 'csv');

fclose($handle);
```

### Progress Tracking

```php
use SQLCraft\Events\ExportProgress;

$dispatcher->addListener(
    ExportProgress::class,
    function (ExportProgress $event) {
        $percent = ($event->rowsProcessed / $event->totalRows) * 100;
        echo sprintf("\rExporting: %.1f%%", $percent);
    }
);

$db->export()->table('orders')->toFile('/tmp/orders.sql');
```

## Import Streaming

Imports process files statement-by-statement:

```php
// Import 1 GB SQL file with ~20 MB memory
$result = $db->import()
    ->fromFile('/tmp/large_backup.sql')
    ->run();

echo "Processed {$result->statementsExecuted} statements\n";
```

### Chunked Import

```php
use SQLCraft\Import\ImportOptions;

$result = $db->import()
    ->fromFile('/tmp/data.sql')
    ->withOptions(new ImportOptions(
        chunkSize: 100,        // Execute 100 statements at a time
        progressInterval: 1000 // Report progress every 1000 statements
    ))
    ->run();
```

## Pagination Strategies

### Offset-Based Pagination

Simple but slower for large offsets:

```php
use SQLCraft\Query\PaginationParams;

$page = new PaginationParams(
    page: 1,
    pageSize: 50
);

$result = $db->query('SELECT * FROM products ORDER BY id')
    ->paginate($page);

foreach ($result->rows() as $row) {
    echo $row['name'] . "\n";
}

echo "Page {$result->pageInfo()->page} of {$result->pageInfo()->totalPages}\n";
```

### Keyset/Cursor Pagination

Much faster for large offsets:

```php
// First page
$result = $db->query(
    'SELECT * FROM orders WHERE id > ? ORDER BY id LIMIT 50',
    [0]
);

$rows = iterator_to_array($result);
$lastId = end($rows)['id'];

// Next page (using last ID as cursor)
$result = $db->query(
    'SELECT * FROM orders WHERE id > ? ORDER BY id LIMIT 50',
    [$lastId]
);
```

### Approximate Count

Use table statistics instead of COUNT(*):

```php
$structure = $db->schema()->describeTable('orders');
$approximateCount = $structure->status->rows; // From table statistics

// Much faster than:
// $exactCount = $db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
```

## Query Optimization

### Limit Result Sets

Always limit queries in production:

```php
// ✅ Good - limited
$result = $db->query('SELECT * FROM users LIMIT 1000');

// ❌ Bad - unbounded
$result = $db->query('SELECT * FROM users');
```

### Use Projections

Select only needed columns:

```php
// ✅ Good - 10 bytes per row
$result = $db->query('SELECT id, name FROM users');

// ❌ Bad - 1000 bytes per row
$result = $db->query('SELECT * FROM users');
```

### Indexed Queries

Ensure queries use indexes:

```php
// Check query plan
$explain = $db->query('EXPLAIN SELECT * FROM orders WHERE customer_id = 123');
foreach ($explain as $row) {
    if (str_contains($row['Extra'] ?? '', 'Using filesort')) {
        echo "Warning: Query not using index efficiently\n";
    }
}
```

## Connection Pooling

Reuse connections to avoid connection overhead:

```php
$factory = new SQLCraftFactory();

// First connection
$db1 = $factory->session($params, name: 'main');

// Reuses the same connection
$db2 = $factory->session($params, name: 'main');

// New connection
$db3 = $factory->session($params, name: 'other');
```

## Batch Operations

### Batch Inserts

More efficient than individual inserts:

```php
// ✅ Good - single transaction, prepared once
$connection = $db->connection();
$connection->beginTransaction();

$stmt = $connection->prepare('INSERT INTO products (name, price) VALUES (?, ?)');

foreach ($products as $product) {
    $stmt->execute([$product['name'], $product['price']]);
}

$connection->commit();

// ❌ Bad - N transactions, N prepares
foreach ($products as $product) {
    $db->query('INSERT INTO products (name, price) VALUES (?, ?)', 
        [$product['name'], $product['price']]);
}
```

### Multi-Row Inserts

MySQL/PostgreSQL support multi-row inserts:

```php
// Build multi-row INSERT
$values = [];
$params = [];

foreach ($products as $product) {
    $values[] = '(?, ?)';
    $params[] = $product['name'];
    $params[] = $product['price'];
}

$sql = 'INSERT INTO products (name, price) VALUES ' . implode(', ', $values);
$db->query($sql, $params);

// Inserts 1000 rows in one query instead of 1000 queries
```

## Metadata Caching

Cache introspection results:

```php
use Psr\SimpleCache\CacheInterface;

$cache = // ... your PSR-16 cache

$factory = new SQLCraftFactory(cache: $cache);
$db = $factory->session($params);

// First call - queries database
$structure = $db->schema()->describeTable('orders'); // ~50ms

// Second call - from cache
$structure = $db->schema()->describeTable('orders'); // ~1ms
```

### Cache Invalidation

```php
use SQLCraft\Events\SchemaChangedEvent;

$dispatcher->addListener(
    SchemaChangedEvent::class,
    function (SchemaChangedEvent $event) use ($cache) {
        // Invalidate affected table
        $cache->delete("table:{$event->database}.{$event->objectName}");
    }
);
```

## Performance Benchmarks

### Query Streaming vs Buffering

```php
// Test with 1 million rows
$start = microtime(true);
$result = $db->query('SELECT * FROM million_rows');
foreach ($result as $row) {
    // Process row
}
$streamTime = microtime(true) - $start;
echo "Streaming: {$streamTime}s, " . memory_get_peak_usage() / 1024 / 1024 . " MB\n";
// Output: Streaming: 2.3s, 10 MB

$start = microtime(true);
$result = $db->query('SELECT * FROM million_rows', buffered: true);
foreach ($result as $row) {
    // Process row
}
$bufferedTime = microtime(true) - $start;
echo "Buffered: {$bufferedTime}s, " . memory_get_peak_usage() / 1024 / 1024 . " MB\n";
// Output: Buffered: 2.5s, 450 MB
```

### Pagination Performance

```php
// Offset-based (slow for large offsets)
// Page 1: 50ms
// Page 100: 200ms
// Page 1000: 2000ms

// Keyset-based (constant time)
// Page 1: 50ms
// Page 100: 50ms
// Page 1000: 50ms
```

## Best Practices

### 1. Stream by Default

```php
// ✅ Good - streaming
$result = $db->query('SELECT * FROM large_table');
foreach ($result as $row) {
    processRow($row);
}

// ❌ Bad - buffering unnecessarily
$rows = iterator_to_array($db->query('SELECT * FROM large_table', buffered: true));
```

### 2. Paginate Large Result Sets

```php
// ✅ Good - paginated
$page = new PaginationParams(page: 1, pageSize: 100);
$result = $db->query('SELECT * FROM orders')->paginate($page);

// ❌ Bad - unbounded
$result = $db->query('SELECT * FROM orders');
```

### 3. Use Indexes

```php
// ✅ Good - indexed column
$result = $db->query('SELECT * FROM orders WHERE customer_id = ?', [123]);

// ❌ Bad - unindexed column
$result = $db->query('SELECT * FROM orders WHERE notes LIKE ?', ['%search%']);
```

### 4. Batch Modifications

```php
// ✅ Good - single transaction
$connection->beginTransaction();
foreach ($updates as $update) {
    $db->query('UPDATE ...', $update);
}
$connection->commit();

// ❌ Bad - N transactions
foreach ($updates as $update) {
    $db->query('UPDATE ...', $update);
}
```

### 5. Cache Metadata

```php
// ✅ Good - with cache
$factory = new SQLCraftFactory(cache: $cache);

// ❌ Bad - no cache
$factory = new SQLCraftFactory();
```

### 6. Monitor Performance

```php
use SQLCraft\Events\AfterQueryExecuted;

$dispatcher->addListener(
    AfterQueryExecuted::class,
    function (AfterQueryExecuted $event) {
        if ($event->duration > 1000) {
            error_log("SLOW: {$event->sql} ({$event->duration}ms)");
        }
    }
);
```

## Troubleshooting

### High Memory Usage

Check if buffering is enabled:

```php
// Find buffered queries
$result = $db->query('SELECT * FROM table', buffered: true); // Remove buffered: true
```

### Slow Pagination

Switch to keyset pagination:

```php
// Instead of:
$result = $db->query('SELECT * FROM orders LIMIT 50 OFFSET 100000');

// Use:
$result = $db->query('SELECT * FROM orders WHERE id > ? ORDER BY id LIMIT 50', [$lastId]);
```

### Connection Exhaustion

Reuse connections:

```php
// Use named connections
$db = $factory->session($params, name: 'main');
```

## Next Steps

- [Query Execution](../user-guide/query-execution.md) - Query patterns
- [Import/Export](../user-guide/import-export.md) - Streaming import/export
- [Transactions](../user-guide/transactions.md) - Transaction management
- [Capabilities](capabilities.md) - Platform capabilities
