# Events

This guide covers the event system for observability, logging, and custom behavior.

## Overview

SQLCraft uses PSR-14 Event Dispatcher for a decoupled event system:

```php
use Psr\EventDispatcher\EventDispatcherInterface;
use SQLCraft\SQLCraftFactory;

$dispatcher = // ... your PSR-14 dispatcher
$factory = new SQLCraftFactory(events: $dispatcher);

$db = $factory->session($params);
// All operations now dispatch events
```

## Event Categories

SQLCraft dispatches 27+ events across six categories:

1. **Connection Events** - Connection lifecycle
2. **Query Events** - Query execution
3. **Transaction Events** - Transaction lifecycle
4. **DDL Events** - Schema changes
5. **Import/Export Events** - Data migration progress
6. **Security Events** - User and privilege changes

## Connection Events

### ConnectionOpened

Fired when a connection is established:

```php
use SQLCraft\Events\ConnectionOpened;

$dispatcher->addListener(
    ConnectionOpened::class,
    function (ConnectionOpened $event) {
        error_log(sprintf(
            "Connected to %s (%s) as %s",
            $event->host,
            $event->platform,
            $event->username
        ));
    }
);
```

**Payload:**
- `host`: string
- `platform`: string (mysql, pgsql, sqlite, sqlserver)
- `username`: ?string
- `database`: ?string
- `timestamp`: DateTimeImmutable

### ConnectionClosed

Fired when a connection is closed:

```php
use SQLCraft\Events\ConnectionClosed;

$dispatcher->addListener(
    ConnectionClosed::class,
    function (ConnectionClosed $event) {
        error_log("Connection closed after {$event->duration}ms");
    }
);
```

**Payload:**
- `connectionName`: string
- `duration`: float (milliseconds)
- `timestamp`: DateTimeImmutable

## Query Events

### BeforeQueryExecuted

Fired before query execution (can modify query):

```php
use SQLCraft\Events\BeforeQueryExecuted;

$dispatcher->addListener(
    BeforeQueryExecuted::class,
    function (BeforeQueryExecuted $event) {
        // Log query
        error_log("Executing: {$event->sql}");
        
        // Modify query (tenant filtering)
        if (str_starts_with($event->sql, 'SELECT')) {
            $event->replaceSql(
                str_replace(
                    'FROM users',
                    'FROM users WHERE tenant_id = ' . $event->connection->getTenantId(),
                    $event->sql
                )
            );
        }
    }
);
```

**Payload:**
- `sql`: string
- `params`: array
- `connection`: ConnectionInterface
- `timestamp`: DateTimeImmutable
- `replaceSql(string $newSql)`: Modify query

### AfterQueryExecuted

Fired after successful query execution:

```php
use SQLCraft\Events\AfterQueryExecuted;

$dispatcher->addListener(
    AfterQueryExecuted::class,
    function (AfterQueryExecuted $event) {
        // Log slow queries
        if ($event->duration > 1000) {
            error_log("SLOW QUERY ({$event->duration}ms): {$event->sql}");
        }
        
        // Metrics
        Metrics::histogram('query.duration', $event->duration);
        Metrics::increment('query.count');
    }
);
```

**Payload:**
- `sql`: string
- `params`: array
- `duration`: float (milliseconds)
- `rowCount`: int
- `connection`: ConnectionInterface
- `timestamp`: DateTimeImmutable

### QueryFailed

Fired when a query fails:

```php
use SQLCraft\Events\QueryFailed;

$dispatcher->addListener(
    QueryFailed::class,
    function (QueryFailed $event) {
        error_log(sprintf(
            "Query failed: %s\nSQL: %s\nError: %s",
            $event->exception->getMessage(),
            $event->sql,
            $event->errorCode
        ));
    }
);
```

**Payload:**
- `sql`: string
- `params`: array
- `exception`: QueryException
- `errorCode`: string
- `duration`: float
- `connection`: ConnectionInterface

## Transaction Events

### TransactionBegun

Fired when transaction starts:

```php
use SQLCraft\Events\TransactionBegun;

$dispatcher->addListener(
    TransactionBegun::class,
    function (TransactionBegun $event) {
        error_log("Transaction started: {$event->connectionName}");
        
        if ($event->isolationLevel) {
            error_log("Isolation: {$event->isolationLevel->value}");
        }
    }
);
```

**Payload:**
- `connectionName`: string
- `isolationLevel`: ?IsolationLevel
- `timestamp`: DateTimeImmutable

### TransactionCommitted

Fired when transaction commits:

```php
use SQLCraft\Events\TransactionCommitted;

$dispatcher->addListener(
    TransactionCommitted::class,
    function (TransactionCommitted $event) {
        error_log(
            "Transaction committed: {$event->connectionName} " .
            "({$event->duration}ms, {$event->queryCount} queries)"
        );
    }
);
```

**Payload:**
- `connectionName`: string
- `duration`: float
- `queryCount`: int
- `timestamp`: DateTimeImmutable

### TransactionRolledBack

Fired when transaction rolls back:

```php
use SQLCraft\Events\TransactionRolledBack;

$dispatcher->addListener(
    TransactionRolledBack::class,
    function (TransactionRolledBack $event) {
        error_log(
            "Transaction rolled back: {$event->connectionName}\n" .
            "Reason: {$event->reason}\n" .
            "Exception: {$event->exception?->getMessage()}"
        );
    }
);
```

**Payload:**
- `connectionName`: string
- `reason`: string
- `exception`: ?Throwable
- `duration`: float
- `timestamp`: DateTimeImmutable

### SavepointCreated

Fired when savepoint is created:

```php
use SQLCraft\Events\SavepointCreated;

$dispatcher->addListener(
    SavepointCreated::class,
    function (SavepointCreated $event) {
        error_log("Savepoint created: {$event->name}");
    }
);
```

### SavepointRolledBack

Fired when rolling back to savepoint:

```php
use SQLCraft\Events\SavepointRolledBack;

$dispatcher->addListener(
    SavepointRolledBack::class,
    function (SavepointRolledBack $event) {
        error_log("Rolled back to savepoint: {$event->name}");
    }
);
```

## DDL Events

### BeforeDdlExecuted

Fired before DDL execution (can cancel):

```php
use SQLCraft\Events\BeforeDdlExecuted;

$dispatcher->addListener(
    BeforeDdlExecuted::class,
    function (BeforeDdlExecuted $event) {
        // Prevent dropping tables in production
        if ($event->operation === 'DROP TABLE' && $_ENV['APP_ENV'] === 'production') {
            $event->cancel();
            throw new \RuntimeException('Cannot drop tables in production');
        }
        
        // Log DDL
        error_log("DDL: {$event->operation} on {$event->objectName}");
    }
);
```

**Payload:**
- `operation`: string (CREATE TABLE, ALTER TABLE, DROP TABLE, etc.)
- `objectType`: string (table, index, view, trigger, etc.)
- `objectName`: string
- `sql`: string
- `connection`: ConnectionInterface
- `cancel()`: Prevent execution

### AfterDdlExecuted

Fired after DDL execution:

```php
use SQLCraft\Events\AfterDdlExecuted;

$dispatcher->addListener(
    AfterDdlExecuted::class,
    function (AfterDdlExecuted $event) {
        // Clear metadata cache
        $cache->delete("schema:{$event->objectName}");
        
        // Audit log
        AuditLog::record(
            action: $event->operation,
            object: $event->objectName,
            user: $event->connection->getUsername(),
            duration: $event->duration
        );
    }
);
```

**Payload:**
- `operation`: string
- `objectType`: string
- `objectName`: string
- `sql`: string
- `duration`: float
- `connection`: ConnectionInterface

### SchemaChangedEvent

Fired after any schema modification:

```php
use SQLCraft\Events\SchemaChangedEvent;

$dispatcher->addListener(
    SchemaChangedEvent::class,
    function (SchemaChangedEvent $event) {
        // Invalidate all caches
        $cache->clear();
        
        // Notify other services
        Redis::publish('schema.changed', json_encode([
            'database' => $event->database,
            'changeType' => $event->changeType,
            'objectName' => $event->objectName,
        ]));
    }
);
```

**Payload:**
- `database`: string
- `changeType`: string (create, alter, drop)
- `objectType`: string
- `objectName`: string
- `timestamp`: DateTimeImmutable

## Import/Export Events

### ExportStarted

Fired when export begins:

```php
use SQLCraft\Events\ExportStarted;

$dispatcher->addListener(
    ExportStarted::class,
    function (ExportStarted $event) {
        echo "Exporting {$event->scope} to {$event->format}...\n";
    }
);
```

**Payload:**
- `scope`: string (table name or database)
- `format`: string (sql, csv, tsv)
- `destination`: string (file path or stream)
- `estimatedRows`: ?int

### ExportProgress

Fired periodically during export:

```php
use SQLCraft\Events\ExportProgress;

$dispatcher->addListener(
    ExportProgress::class,
    function (ExportProgress $event) {
        $percent = ($event->rowsProcessed / $event->totalRows) * 100;
        printf("\rProgress: %.1f%% (%d / %d rows)", $percent, $event->rowsProcessed, $event->totalRows);
    }
);
```

**Payload:**
- `rowsProcessed`: int
- `totalRows`: int
- `bytesWritten`: int
- `duration`: float

### ExportFinished

Fired when export completes:

```php
use SQLCraft\Events\ExportFinished;

$dispatcher->addListener(
    ExportFinished::class,
    function (ExportFinished $event) {
        echo "\nExport complete!\n";
        echo "Rows: {$event->totalRows}\n";
        echo "Size: " . formatBytes($event->bytesWritten) . "\n";
        echo "Duration: {$event->duration}ms\n";
    }
);
```

**Payload:**
- `totalRows`: int
- `bytesWritten`: int
- `duration`: float
- `destination`: string

### ImportStarted

Fired when import begins:

```php
use SQLCraft\Events\ImportStarted;

$dispatcher->addListener(
    ImportStarted::class,
    function (ImportStarted $event) {
        echo "Importing from {$event->source} ({$event->format})...\n";
    }
);
```

**Payload:**
- `source`: string (file path or stream)
- `format`: string
- `estimatedStatements`: ?int

### ImportProgress

Fired periodically during import:

```php
use SQLCraft\Events\ImportProgress;

$dispatcher->addListener(
    ImportProgress::class,
    function (ImportProgress $event) {
        printf(
            "\rProcessed: %d statements, %d rows",
            $event->statementsExecuted,
            $event->rowsAffected
        );
    }
);
```

**Payload:**
- `statementsExecuted`: int
- `rowsAffected`: int
- `bytesRead`: int
- `duration`: float

### ImportFinished

Fired when import completes:

```php
use SQLCraft\Events\ImportFinished;

$dispatcher->addListener(
    ImportFinished::class,
    function (ImportFinished $event) {
        echo "\nImport complete!\n";
        echo "Statements: {$event->statementsExecuted}\n";
        echo "Rows: {$event->rowsAffected}\n";
        echo "Duration: {$event->duration}ms\n";
    }
);
```

**Payload:**
- `statementsExecuted`: int
- `rowsAffected`: int
- `duration`: float
- `source`: string

### ImportFailed

Fired when import fails:

```php
use SQLCraft\Events\ImportFailed;

$dispatcher->addListener(
    ImportFailed::class,
    function (ImportFailed $event) {
        error_log(
            "Import failed at statement {$event->failedAtStatement}: " .
            $event->exception->getMessage()
        );
    }
);
```

**Payload:**
- `exception`: ImportFailedException
- `failedAtStatement`: int
- `statementsExecuted`: int
- `source`: string

## Security Events

### UserCreated

Fired when user is created:

```php
use SQLCraft\Events\UserCreated;

$dispatcher->addListener(
    UserCreated::class,
    function (UserCreated $event) {
        AuditLog::record(
            action: 'USER_CREATED',
            user: $event->username,
            host: $event->host,
            createdBy: $event->createdBy
        );
    }
);
```

**Payload:**
- `username`: string
- `host`: string
- `createdBy`: string
- `timestamp`: DateTimeImmutable

### UserDropped

Fired when user is dropped:

```php
use SQLCraft\Events\UserDropped;

$dispatcher->addListener(
    UserDropped::class,
    function (UserDropped $event) {
        AuditLog::record(
            action: 'USER_DROPPED',
            user: $event->username,
            droppedBy: $event->droppedBy
        );
    }
);
```

### PrivilegeGranted

Fired when privileges are granted:

```php
use SQLCraft\Events\PrivilegeGranted;

$dispatcher->addListener(
    PrivilegeGranted::class,
    function (PrivilegeGranted $event) {
        error_log(sprintf(
            "GRANT %s ON %s TO %s%s",
            implode(', ', $event->privileges),
            $event->object,
            $event->user,
            $event->withGrantOption ? ' WITH GRANT OPTION' : ''
        ));
    }
);
```

**Payload:**
- `privileges`: array<string>
- `object`: string
- `user`: string
- `withGrantOption`: bool
- `grantedBy`: string

### PrivilegeRevoked

Fired when privileges are revoked:

```php
use SQLCraft\Events\PrivilegeRevoked;

$dispatcher->addListener(
    PrivilegeRevoked::class,
    function (PrivilegeRevoked $event) {
        error_log(sprintf(
            "REVOKE %s ON %s FROM %s",
            implode(', ', $event->privileges),
            $event->object,
            $event->user
        ));
    }
);
```

## Custom Event Listeners

### Simple Listener

```php
class QueryLogger
{
    public function __invoke(AfterQueryExecuted $event): void
    {
        file_put_contents(
            '/var/log/queries.log',
            sprintf(
                "[%s] %s (%dms)\n",
                $event->timestamp->format('Y-m-d H:i:s'),
                $event->sql,
                $event->duration
            ),
            FILE_APPEND
        );
    }
}

$dispatcher->addListener(AfterQueryExecuted::class, new QueryLogger());
```

### Stateful Listener

```php
class PerformanceMonitor
{
    private array $stats = [];
    
    public function onQueryExecuted(AfterQueryExecuted $event): void
    {
        $this->stats[] = [
            'sql' => $event->sql,
            'duration' => $event->duration,
            'timestamp' => $event->timestamp,
        ];
    }
    
    public function getSlowestQueries(int $limit = 10): array
    {
        usort($this->stats, fn($a, $b) => $b['duration'] <=> $a['duration']);
        return array_slice($this->stats, 0, $limit);
    }
}

$monitor = new PerformanceMonitor();
$dispatcher->addListener(AfterQueryExecuted::class, [$monitor, 'onQueryExecuted']);
```

## Event Propagation

### Stopping Propagation

```php
use SQLCraft\Events\BeforeDdlExecuted;

$dispatcher->addListener(
    BeforeDdlExecuted::class,
    function (BeforeDdlExecuted $event) {
        if ($event->operation === 'DROP DATABASE') {
            $event->stopPropagation();
            throw new \RuntimeException('DROP DATABASE is not allowed');
        }
    }
);
```

## Common Patterns

### Query Performance Monitoring

```php
$dispatcher->addListener(
    AfterQueryExecuted::class,
    function (AfterQueryExecuted $event) {
        // Warn on slow queries
        if ($event->duration > 1000) {
            Slack::send("Slow query detected: {$event->sql} ({$event->duration}ms)");
        }
        
        // Send to monitoring
        Datadog::histogram('query.duration', $event->duration, [
            'connection' => $event->connection->getName(),
            'platform' => $event->connection->getPlatform()->getName(),
        ]);
    }
);
```

### Audit Logging

```php
$dispatcher->addListener(
    AfterDdlExecuted::class,
    function (AfterDdlExecuted $event) {
        DB::table('audit_log')->insert([
            'action' => $event->operation,
            'object_type' => $event->objectType,
            'object_name' => $event->objectName,
            'user' => $event->connection->getUsername(),
            'sql' => $event->sql,
            'duration' => $event->duration,
            'created_at' => now(),
        ]);
    }
);
```

### Multi-Tenant Filtering

```php
$dispatcher->addListener(
    BeforeQueryExecuted::class,
    function (BeforeQueryExecuted $event) {
        $tenantId = Auth::user()->tenant_id;
        
        // Inject tenant filter
        if (preg_match('/FROM\s+(\w+)/i', $event->sql, $matches)) {
            $table = $matches[1];
            if (in_array($table, ['orders', 'customers', 'products'])) {
                $event->replaceSql(
                    str_replace(
                        "FROM $table",
                        "FROM $table WHERE tenant_id = $tenantId",
                        $event->sql
                    )
                );
            }
        }
    }
);
```

### Cache Invalidation

```php
$dispatcher->addListener(
    SchemaChangedEvent::class,
    function (SchemaChangedEvent $event) {
        // Invalidate schema cache
        Cache::tags(['schema', $event->database])->flush();
        
        // Invalidate specific object
        Cache::forget("table:{$event->database}.{$event->objectName}");
    }
);
```

## Testing with Events

### Asserting Events Were Dispatched

```php
use PHPUnit\Framework\TestCase;

class ExportTest extends TestCase
{
    public function testExportDispatchesEvents(): void
    {
        $dispatcher = new FakeEventDispatcher();
        $factory = new SQLCraftFactory(events: $dispatcher);
        $db = $factory->session($params);
        
        $db->export()->table('users')->toFile('/tmp/users.sql');
        
        $dispatcher->assertDispatched(ExportStarted::class);
        $dispatcher->assertDispatched(ExportFinished::class);
        $dispatcher->assertNotDispatched(ExportFailed::class);
    }
}
```

## Next Steps

- [Capabilities](../advanced/capabilities.md) - Capability system
- [Framework Integration](../advanced/framework-integration.md) - Integrate with frameworks
- [API Reference](../api/overview.md) - Complete API documentation
