# Transactions

This guide covers transaction management, isolation levels, savepoints, and best practices.

## Basic Transactions

### Manual Transaction Control

```php
$connection = $db->connection();

$connection->beginTransaction();

try {
    $db->query('INSERT INTO orders (customer_id, total) VALUES (?, ?)', [1, 100.00]);
    $db->query('UPDATE customers SET total_orders = total_orders + 1 WHERE id = ?', [1]);
    $db->query('INSERT INTO order_items (order_id, product_id) VALUES (?, ?)', [1, 10]);
    
    $connection->commit();
    echo "Transaction committed\n";
    
} catch (\Exception $e) {
    $connection->rollback();
    echo "Transaction rolled back: " . $e->getMessage() . "\n";
    throw $e;
}
```

### Automatic Rollback

Check transaction state:

```php
if ($connection->inTransaction()) {
    echo "Transaction is active\n";
}
```

## Savepoints (Nested Transactions)

Savepoints allow you to create rollback points within a transaction:

```php
$connection->beginTransaction();

try {
    $db->query('INSERT INTO orders (customer_id, total) VALUES (?, ?)', [1, 100.00]);
    
    // Create savepoint
    $connection->savepoint('before_items');
    
    try {
        $db->query('INSERT INTO order_items (order_id, product_id) VALUES (?, ?)', [1, 999]);
        // Product doesn't exist - will fail
        
    } catch (\Exception $e) {
        // Rollback just the items, keep the order
        $connection->rollbackToSavepoint('before_items');
        echo "Items rolled back, order preserved\n";
    }
    
    $connection->commit();
    
} catch (\Exception $e) {
    $connection->rollback();
}
```

### Savepoint Methods

```php
// Create savepoint
$connection->savepoint('point1');

// Rollback to savepoint
$connection->rollbackToSavepoint('point1');

// Release savepoint (commit it)
$connection->releaseSavepoint('point1');
```

## Isolation Levels

Set transaction isolation level:

```php
use SQLCraft\ValueObjects\IsolationLevel;

// Set before beginning transaction
$connection->setIsolationLevel(IsolationLevel::ReadCommitted);
$connection->beginTransaction();
// ... perform operations
$connection->commit();
```

### Available Isolation Levels

| Level | Description | Dirty Read | Non-Repeatable Read | Phantom Read |
|-------|-------------|------------|---------------------|--------------|
| `ReadUncommitted` | Lowest isolation, highest performance | Yes | Yes | Yes |
| `ReadCommitted` | Default for most databases | No | Yes | Yes |
| `RepeatableRead` | Default for MySQL | No | No | Yes |
| `Serializable` | Highest isolation, lowest performance | No | No | No |

### MySQL/MariaDB

```php
use SQLCraft\ValueObjects\IsolationLevel;

// Read Committed (prevents dirty reads)
$connection->setIsolationLevel(IsolationLevel::ReadCommitted);

// Repeatable Read (default, prevents dirty and non-repeatable reads)
$connection->setIsolationLevel(IsolationLevel::RepeatableRead);

// Serializable (prevents all anomalies)
$connection->setIsolationLevel(IsolationLevel::Serializable);
```

### PostgreSQL

```php
// PostgreSQL supports all standard levels
$connection->setIsolationLevel(IsolationLevel::ReadCommitted); // Default
$connection->setIsolationLevel(IsolationLevel::RepeatableRead);
$connection->setIsolationLevel(IsolationLevel::Serializable);
```

### SQLite

```php
// SQLite has simplified transaction modes
use SQLCraft\ValueObjects\SqliteTransactionMode;

// Deferred (default) - lock acquired on first read/write
$connection->beginTransaction(SqliteTransactionMode::Deferred);

// Immediate - write lock acquired immediately
$connection->beginTransaction(SqliteTransactionMode::Immediate);

// Exclusive - exclusive lock acquired immediately
$connection->beginTransaction(SqliteTransactionMode::Exclusive);
```

## Deadlock Handling

Detect and retry deadlocks:

```php
use SQLCraft\Exceptions\DeadlockException;

$maxRetries = 3;
$attempt = 0;

while ($attempt < $maxRetries) {
    try {
        $connection->beginTransaction();
        
        $db->query('UPDATE accounts SET balance = balance - 100 WHERE id = ?', [1]);
        $db->query('UPDATE accounts SET balance = balance + 100 WHERE id = ?', [2]);
        
        $connection->commit();
        break; // Success
        
    } catch (DeadlockException $e) {
        $connection->rollback();
        $attempt++;
        
        if ($attempt >= $maxRetries) {
            throw new \RuntimeException('Max retries exceeded', 0, $e);
        }
        
        // Exponential backoff
        usleep(100000 * pow(2, $attempt)); // 100ms, 200ms, 400ms
    }
}
```

### Detecting Retryable Errors

```php
use SQLCraft\Exceptions\DeadlockException;
use SQLCraft\Exceptions\LockTimeoutException;

try {
    // ... transaction code
} catch (DeadlockException | LockTimeoutException $e) {
    // These are retryable
    if ($e->retryable) {
        // Retry logic
    }
}
```

## Lock Timeouts

Set lock wait timeout:

```php
// MySQL
$db->query('SET SESSION innodb_lock_wait_timeout = 5');

// PostgreSQL
$db->query('SET lock_timeout = 5000'); // milliseconds

// Then execute transaction
$connection->beginTransaction();
try {
    // ... operations that may wait for locks
    $connection->commit();
} catch (LockTimeoutException $e) {
    $connection->rollback();
    echo "Lock timeout after {$e->timeoutSeconds}s\n";
}
```

## Transaction Events

Listen to transaction lifecycle:

```php
use SQLCraft\Events\{
    TransactionBegun,
    TransactionCommitted,
    TransactionRolledBack
};

$dispatcher->addListener(
    TransactionBegun::class,
    function (TransactionBegun $event) {
        echo "Transaction started on connection: {$event->connectionName}\n";
    }
);

$dispatcher->addListener(
    TransactionCommitted::class,
    function (TransactionCommitted $event) {
        echo "Transaction committed in {$event->duration}ms\n";
    }
);

$dispatcher->addListener(
    TransactionRolledBack::class,
    function (TransactionRolledBack $event) {
        echo "Transaction rolled back: {$event->reason}\n";
    }
);
```

## Best Practices

### 1. Keep Transactions Short

```php
// ✅ Good - minimal work in transaction
$connection->beginTransaction();
$db->query('UPDATE accounts SET balance = balance - 100 WHERE id = ?', [1]);
$db->query('UPDATE accounts SET balance = balance + 100 WHERE id = ?', [2]);
$connection->commit();

// ❌ Bad - long-running operations
$connection->beginTransaction();
$db->query('UPDATE accounts ...');
sendEmail($user); // External I/O
callExternalApi(); // Network call
sleep(10); // Long delay
$connection->commit();
```

### 2. Always Handle Rollback

```php
// ✅ Good - explicit rollback on error
try {
    $connection->beginTransaction();
    // ... operations
    $connection->commit();
} catch (\Exception $e) {
    $connection->rollback();
    throw $e;
}

// ❌ Bad - no rollback
$connection->beginTransaction();
$db->query('INSERT ...');
$connection->commit();
```

### 3. Don't Nest Transactions (Use Savepoints)

```php
// ✅ Good - use savepoints for nesting
$connection->beginTransaction();
$db->query('INSERT INTO orders ...');

$connection->savepoint('items');
try {
    $db->query('INSERT INTO order_items ...');
} catch (\Exception $e) {
    $connection->rollbackToSavepoint('items');
}

$connection->commit();

// ❌ Bad - nested beginTransaction() throws exception
$connection->beginTransaction();
$connection->beginTransaction(); // Exception!
```

### 4. Set Appropriate Isolation Level

```php
// ✅ Good - match isolation to use case
// For financial transfers - use Serializable
$connection->setIsolationLevel(IsolationLevel::Serializable);

// For reading reports - use Read Committed
$connection->setIsolationLevel(IsolationLevel::ReadCommitted);

// ❌ Bad - always using highest isolation
$connection->setIsolationLevel(IsolationLevel::Serializable); // For every query
```

### 5. Handle Deadlocks Gracefully

```php
// ✅ Good - retry with exponential backoff
$retries = 3;
while ($retries--) {
    try {
        $connection->beginTransaction();
        // ... operations
        $connection->commit();
        break;
    } catch (DeadlockException $e) {
        $connection->rollback();
        if ($retries === 0) throw $e;
        usleep(100000 * pow(2, 3 - $retries));
    }
}

// ❌ Bad - don't retry or infinite loop
try {
    $connection->beginTransaction();
    // ... operations
    $connection->commit();
} catch (DeadlockException $e) {
    // Give up immediately
}
```

### 6. Use Read-Only Transactions for Queries

```php
// PostgreSQL
$db->query('BEGIN TRANSACTION READ ONLY');
$result = $db->query('SELECT * FROM large_table');
// ... process results
$connection->commit();

// MySQL
$db->query('START TRANSACTION READ ONLY');
```

### 7. Avoid SELECT FOR UPDATE Unless Necessary

```php
// ✅ Good - use when you need to lock
$connection->beginTransaction();
$result = $db->query('SELECT balance FROM accounts WHERE id = ? FOR UPDATE', [1]);
$balance = $result->fetchColumn('balance');
$db->query('UPDATE accounts SET balance = ? WHERE id = ?', [$balance - 100, 1]);
$connection->commit();

// ❌ Bad - unnecessary locking
$result = $db->query('SELECT * FROM products FOR UPDATE'); // Locks all rows!
```

## Platform-Specific Features

### MySQL/MariaDB

```php
// Consistent snapshot (for backups)
$db->query('START TRANSACTION WITH CONSISTENT SNAPSHOT');

// Lock tables
$db->query('LOCK TABLES orders WRITE, customers READ');
// ... operations
$db->query('UNLOCK TABLES');

// Check InnoDB status
$result = $db->query('SHOW ENGINE INNODB STATUS');
```

### PostgreSQL

```php
// Advisory locks
$db->query('SELECT pg_advisory_lock(123)');
// ... critical section
$db->query('SELECT pg_advisory_unlock(123)');

// Table-level locks
$connection->beginTransaction();
$db->query('LOCK TABLE orders IN EXCLUSIVE MODE');
// ... operations
$connection->commit();

// Check locks
$result = $db->query('SELECT * FROM pg_locks');
```

### SQLite

```php
// WAL mode for better concurrency
$db->query('PRAGMA journal_mode = WAL');

// Busy timeout
$db->query('PRAGMA busy_timeout = 5000'); // 5 seconds

// Check if database is locked
try {
    $connection->beginTransaction(SqliteTransactionMode::Immediate);
} catch (DatabaseLockedException $e) {
    echo "Database is locked\n";
}
```

### SQL Server

```php
// Snapshot isolation
$db->query('SET TRANSACTION ISOLATION LEVEL SNAPSHOT');

// Set lock timeout
$db->query('SET LOCK_TIMEOUT 5000'); // milliseconds

// Check locks
$result = $db->query('SELECT * FROM sys.dm_tran_locks');
```

## Common Patterns

### Transfer Between Accounts

```php
function transfer(DatabaseSession $db, int $from, int $to, float $amount): void
{
    $connection = $db->connection();
    $connection->setIsolationLevel(IsolationLevel::Serializable);
    $connection->beginTransaction();
    
    try {
        // Lock both accounts
        $result = $db->query(
            'SELECT balance FROM accounts WHERE id IN (?, ?) FOR UPDATE',
            [$from, $to]
        );
        
        // Deduct from source
        $db->query(
            'UPDATE accounts SET balance = balance - ? WHERE id = ?',
            [$amount, $from]
        );
        
        // Add to destination
        $db->query(
            'UPDATE accounts SET balance = balance + ? WHERE id = ?',
            [$amount, $to]
        );
        
        $connection->commit();
        
    } catch (\Exception $e) {
        $connection->rollback();
        throw new TransferFailedException('Transfer failed', 0, $e);
    }
}
```

### Batch Insert with Rollback

```php
function batchInsert(DatabaseSession $db, array $rows): int
{
    $connection = $db->connection();
    $connection->beginTransaction();
    $inserted = 0;
    
    try {
        foreach ($rows as $row) {
            $db->query(
                'INSERT INTO products (name, price) VALUES (?, ?)',
                [$row['name'], $row['price']]
            );
            $inserted++;
        }
        
        $connection->commit();
        return $inserted;
        
    } catch (\Exception $e) {
        $connection->rollback();
        throw new \RuntimeException("Inserted $inserted rows before error", 0, $e);
    }
}
```

### Optimistic Locking with Version

```php
function updateWithVersion(DatabaseSession $db, int $id, array $data, int $expectedVersion): bool
{
    $connection = $db->connection();
    $connection->beginTransaction();
    
    try {
        $result = $db->query(
            'UPDATE products SET name = ?, version = version + 1 
             WHERE id = ? AND version = ?',
            [$data['name'], $id, $expectedVersion]
        );
        
        if ($result->rowCount() === 0) {
            $connection->rollback();
            return false; // Concurrent modification
        }
        
        $connection->commit();
        return true;
        
    } catch (\Exception $e) {
        $connection->rollback();
        throw $e;
    }
}
```

## Troubleshooting

### Transaction Not Committed

Check for exceptions:

```php
$connection->beginTransaction();
try {
    $db->query('INSERT ...');
    // If exception here, commit never runs
    $connection->commit();
} catch (\Exception $e) {
    $connection->rollback();
    error_log("Transaction failed: " . $e->getMessage());
}
```

### Deadlock Debugging

Log queries involved:

```php
use SQLCraft\Events\BeforeQueryExecuted;

$dispatcher->addListener(
    BeforeQueryExecuted::class,
    function (BeforeQueryExecuted $event) {
        if ($event->connection->inTransaction()) {
            error_log("In transaction: " . $event->sql);
        }
    }
);
```

### Long-Running Transactions

Monitor transaction duration:

```php
$start = microtime(true);
$connection->beginTransaction();

try {
    // ... operations
    $connection->commit();
    
    $duration = (microtime(true) - $start) * 1000;
    if ($duration > 1000) {
        error_log("Slow transaction: {$duration}ms");
    }
    
} catch (\Exception $e) {
    $connection->rollback();
    throw $e;
}
```

## Next Steps

- [Query Execution](query-execution.md) - Execute queries within transactions
- [Events](events.md) - Monitor transaction events
- [Security](security.md) - Transaction privilege requirements
- [Streaming](../advanced/streaming.md) - Memory-efficient transaction processing
