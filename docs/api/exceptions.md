# Exception Hierarchy

SQLCraft uses typed exceptions with domain-specific payload fields to give you precise
context about failures. Every exception extends `SQLCraftException`, which itself extends
`\RuntimeException`.

## Base Class: SQLCraftException

```php
namespace SQLCraft\Exceptions;

abstract class SQLCraftException extends \RuntimeException
{
    public function __toString(): string
    {
        return sprintf(
            '%s: %s in %s:%d\nStack trace:\n%s',
            static::class,
            $this->getMessage(),
            $this->getFile(),
            $this->getLine(),
            $this->getTraceAsString()
        );
    }
}
```

All SQLCraft exceptions descend from this class, so you can catch them all with a single
`catch (SQLCraftException $e)` block if you want blanket error handling.

## Exception Tree

```
SQLCraftException
├─ ConnectionException
│  ├─ ConnectionFailedException
│  ├─ AuthenticationException
│  └─ ConnectionLostException
├─ QueryException
│  ├─ SyntaxErrorException
│  ├─ QueryTimeoutException
│  ├─ ConstraintViolationException
│  │  ├─ UniqueConstraintException
│  │  ├─ ForeignKeyConstraintException
│  │  ├─ CheckConstraintException (not in src/ yet, reserved)
│  │  └─ NotNullConstraintException (not in src/ yet, reserved)
│  └─ DeadlockException
├─ CapabilityException
│  └─ CapabilityNotSupportedException
├─ MetadataException
│  └─ ObjectNotFoundException
├─ SecurityException
│  └─ InsufficientPrivilegesException
├─ ImportExportException
│  ├─ ImportFailedException
│  └─ ExportFailedException
├─ DriverException
│  ├─ DriverNotFoundException
│  ├─ DriverMisconfiguredException
│  └─ ExtensionMissingException
└─ (StreamingResultException, OperationCancelledException, InvalidOperatorException, etc.)
```

## ConnectionException Branch

**Base class**: `SQLCraft\Exceptions\ConnectionException`

Thrown when the connection to the database fails or is lost.

### Payload

```php
abstract class ConnectionException extends SQLCraftException
{
    public readonly string $host;
    public readonly string $driver;
}
```

| Field | Type | Description |
|---|---|---|
| `host` | `string` | Hostname or IP from `ConnectionParameters` |
| `driver` | `string` | Driver name (mysql, pgsql, sqlite, sqlsrv) |

### ConnectionFailedException

```php
final class ConnectionFailedException extends ConnectionException
```

Thrown when the initial connection attempt fails. Common causes: wrong host, port not
open, network unreachable, driver extension not loaded.

**Example:**

```php
use SQLCraft\Enums\DatabaseDriver;
use SQLCraft\Exceptions\ConnectionFailedException;
use SQLCraft\SQLCraftFactory;
use SQLCraft\ValueObjects\ConnectionParameters;

try {
    $session = $factory->session(new ConnectionParameters(
        host: 'nonexistent.local',
        port: 5432,
        database: 'mydb',
        driver: DatabaseDriver::PostgreSQL,
    ));
} catch (ConnectionFailedException $e) {
    echo "Failed to connect to {$e->host} using driver {$e->driver}\n";
    echo $e->getMessage();
}
```

### AuthenticationException

```php
final class AuthenticationException extends ConnectionException
```

Thrown when credentials are rejected by the server.

**Example:**

```php
use SQLCraft\Enums\DatabaseDriver;
use SQLCraft\Exceptions\AuthenticationException;

try {
    $session = $factory->session(new ConnectionParameters(
        host: '127.0.0.1',
        port: 5432,
        database: 'mydb',
        username: 'wronguser',
        password: 'wrongpass',
        driver: DatabaseDriver::PostgreSQL,
    ));
} catch (AuthenticationException $e) {
    echo "Authentication failed for driver {$e->driver} at {$e->host}\n";
}
```

### ConnectionLostException

```php
final class ConnectionLostException extends ConnectionException
```

Thrown when an established connection is lost mid-operation (e.g., network partition,
server restart, idle timeout).

**Example:**

```php
use SQLCraft\Exceptions\ConnectionLostException;

try {
    $result = $session->query('SELECT SLEEP(3600)');  // server killed during sleep
} catch (ConnectionLostException $e) {
    echo "Connection to {$e->host} was lost\n";
    // attempt reconnection
}
```

## QueryException Branch

**Base class**: `SQLCraft\Exceptions\QueryException`

Thrown when query execution fails.

### Payload

```php
class QueryException extends SQLCraftException
{
    public readonly string $sql;
}
```

| Field | Type | Description |
|---|---|---|
| `sql` | `string` | The SQL statement that failed (may be truncated if very long) |

### SyntaxErrorException

```php
final class SyntaxErrorException extends QueryException
```

Thrown when the SQL parser rejects the query due to invalid syntax.

**Example:**

```php
use SQLCraft\Exceptions\SyntaxErrorException;

try {
    $session->query('SELEKT * FROM users');  // typo
} catch (SyntaxErrorException $e) {
    echo "Syntax error in SQL: {$e->sql}\n";
    echo $e->getMessage();
}
```

### QueryTimeoutException

```php
final class QueryTimeoutException extends QueryException
```

Thrown when a query exceeds the configured timeout.

**Example:**

```php
use SQLCraft\Exceptions\QueryTimeoutException;

try {
    $session->connection()->execute('SET SESSION max_execution_time = 1000');
    $session->query('SELECT * FROM huge_table WHERE expensive_function(col)');
} catch (QueryTimeoutException $e) {
    echo "Query timed out: {$e->sql}\n";
}
```

### ConstraintViolationException

```php
class ConstraintViolationException extends QueryException
{
    public readonly string $constraintName;
    public readonly string $table;
}
```

Base class for all database constraint violations.

| Field | Type | Description |
|---|---|---|
| `constraintName` | `string` | Name of the violated constraint |
| `table` | `string` | Table where the violation occurred |
| `sql` | `string` | (inherited) The failing SQL |

**Subclasses:**

#### UniqueConstraintException

```php
final class UniqueConstraintException extends ConstraintViolationException
```

Thrown when an INSERT or UPDATE violates a UNIQUE constraint or PRIMARY KEY.

**Example:**

```php
use SQLCraft\Exceptions\UniqueConstraintException;

try {
    $session->query('INSERT INTO users (id, email) VALUES (?, ?)', [1, 'test@example.com']);
    $session->query('INSERT INTO users (id, email) VALUES (?, ?)', [1, 'other@example.com']);
} catch (UniqueConstraintException $e) {
    echo "Duplicate entry in table {$e->table}\n";
    echo "Constraint: {$e->constraintName}\n";
}
```

#### ForeignKeyConstraintException

```php
final class ForeignKeyConstraintException extends ConstraintViolationException
```

Thrown when an INSERT, UPDATE, or DELETE violates a foreign key constraint.

**Example:**

```php
use SQLCraft\Exceptions\ForeignKeyConstraintException;

try {
    $session->query('INSERT INTO orders (user_id) VALUES (?)', [999]);  // user_id 999 doesn't exist
} catch (ForeignKeyConstraintException $e) {
    echo "Foreign key violation: {$e->constraintName} on table {$e->table}\n";
}
```

#### CheckConstraintException

Reserved for future use. Not yet implemented in `src/Exceptions/`, but the slot is
reserved in the hierarchy.

#### NotNullConstraintException

Reserved for future use. Not yet implemented in `src/Exceptions/`, but the slot is
reserved in the hierarchy.

### DeadlockException

```php
final class DeadlockException extends QueryException
{
    public readonly bool $retryable;
}
```

Thrown when a transaction is rolled back due to deadlock. The `$retryable` field is
always `true` to signal that retrying the transaction is safe.

| Field | Type | Description |
|---|---|---|
| `retryable` | `bool` | Always `true` (indicates safe to retry) |
| `sql` | `string` | (inherited) The SQL that triggered the deadlock |

**Example:**

```php
use SQLCraft\Exceptions\DeadlockException;

$retries = 3;
while ($retries > 0) {
    try {
        $session->connection()->beginTransaction();
        $session->query('UPDATE accounts SET balance = balance - 100 WHERE id = 1');
        $session->query('UPDATE accounts SET balance = balance + 100 WHERE id = 2');
        $session->connection()->commit();
        break;
    } catch (DeadlockException $e) {
        $session->connection()->rollBack();
        if ($e->retryable && $retries > 1) {
            $retries--;
            usleep(100_000);  // backoff
            continue;
        }
        throw $e;
    }
}
```

## CapabilityException Branch

**Base class**: `SQLCraft\Exceptions\CapabilityException`

### CapabilityNotSupportedException

```php
final class CapabilityNotSupportedException extends CapabilityException
{
    public readonly Capability|ExtendedCapability $capability;
    public readonly string $platform;
    public readonly string $version;
}
```

Thrown by `CapabilitySet::require()` when a requested capability is not supported.

| Field | Type | Description |
|---|---|---|
| `capability` | `Capability\|ExtendedCapability` | The missing capability |
| `platform` | `string` | Platform name (mysql, pgsql, etc.) |
| `version` | `string` | Server version string |

**Example:**

```php
use SQLCraft\Capabilities\Capability;
use SQLCraft\Capabilities\CapabilityNotSupportedException;

$caps = $session->connection()->getPlatform()
    ->getCapabilitySet($session->connection()->getServerVersion());

try {
    $caps->require(Capability::Sequence);
    $session->ddl()->createSequence('order_seq')->execute($session->connection());
} catch (CapabilityNotSupportedException $e) {
    echo "Sequences not supported on {$e->platform} {$e->version}\n";
    // fall back to AUTO_INCREMENT
}
```

## MetadataException Branch

**Base class**: `SQLCraft\Exceptions\MetadataException`

### ObjectNotFoundException

```php
final class ObjectNotFoundException extends MetadataException
{
    public readonly string $qualifiedName;
}
```

Thrown when introspection methods (e.g., `describeTable()`) are called on a non-existent
object.

| Field | Type | Description |
|---|---|---|
| `qualifiedName` | `string` | The name of the missing object |

**Example:**

```php
use SQLCraft\Exceptions\ObjectNotFoundException;
use SQLCraft\ValueObjects\QualifiedName;

try {
    $structure = $session->schema()->describeTable(QualifiedName::simple('nonexistent'));
} catch (ObjectNotFoundException $e) {
    echo "Table {$e->qualifiedName} does not exist\n";
}
```

## SecurityException Branch

**Base class**: `SQLCraft\Exceptions\SecurityException`

### InsufficientPrivilegesException

```php
final class InsufficientPrivilegesException extends SecurityException
{
    public readonly string $privilege;
    public readonly string $object;
}
```

Thrown when the current user lacks the required privilege to perform an operation.

| Field | Type | Description |
|---|---|---|
| `privilege` | `string` | The missing privilege (e.g., `DROP`, `ALTER`) |
| `object` | `string` | The object on which the privilege is missing |

**Example:**

```php
use SQLCraft\Exceptions\InsufficientPrivilegesException;

try {
    $session->ddl()->dropTable('users')->execute($session->connection());
} catch (InsufficientPrivilegesException $e) {
    echo "User lacks {$e->privilege} privilege on {$e->object}\n";
}
```

## ImportExportException Branch

**Base class**: `SQLCraft\Exceptions\ImportExportException`

### ImportFailedException

```php
final class ImportFailedException extends ImportExportException
{
    public readonly ?int $statementIndex;
    public readonly ?int $rowIndex;
}
```

Thrown when an import operation fails.

| Field | Type | Description |
|---|---|---|
| `statementIndex` | `?int` | Index of the failing statement (0-based), or `null` |
| `rowIndex` | `?int` | Row number in the source file (1-based), or `null` |

**Example:**

```php
use SQLCraft\Exceptions\ImportFailedException;
use SQLCraft\Import\FileImportSource;
use SQLCraft\Import\ImportOptions;

try {
    $result = $session->import()->importSql(
        new FileImportSource('/tmp/dump.sql'),
        ImportOptions::default(),
    );
} catch (ImportFailedException $e) {
    echo "Import failed at statement {$e->statementIndex}, row {$e->rowIndex}\n";
    echo $e->getMessage();
}
```

### ExportFailedException

```php
final class ExportFailedException extends ImportExportException
{
    public readonly ?int $statementIndex;
    public readonly ?int $rowIndex;
}
```

Thrown when an export operation fails.

| Field | Type | Description |
|---|---|---|
| `statementIndex` | `?int` | Index of the failing export statement, or `null` |
| `rowIndex` | `?int` | Row number in the result set, or `null` |

**Example:**

```php
use SQLCraft\Exceptions\ExportFailedException;
use SQLCraft\Export\DumpOptions;
use SQLCraft\Export\ResourceSink;

try {
    $sink = new ResourceSink(fopen('/tmp/backup.sql', 'wb'));
    $session->export()->dump(DumpOptions::full(), $sink);
} catch (ExportFailedException $e) {
    echo "Export failed at row {$e->rowIndex}\n";
}
```

## When Each Exception is Thrown

| Exception | Common Triggers |
|---|---|
| `ConnectionFailedException` | Wrong host/port, network down, service not running |
| `AuthenticationException` | Wrong username/password, user not allowed from host |
| `ConnectionLostException` | Server killed connection, network partition mid-query |
| `SyntaxErrorException` | Typo in SQL, unsupported syntax for current engine version |
| `QueryTimeoutException` | Query runs longer than `max_execution_time` or statement timeout |
| `UniqueConstraintException` | Duplicate PRIMARY KEY or UNIQUE index value |
| `ForeignKeyConstraintException` | Referenced row missing on INSERT, or DELETE blocked by dependent rows |
| `DeadlockException` | Two transactions lock rows in opposite order |
| `CapabilityNotSupportedException` | `CapabilitySet::require()` called for absent capability |
| `ObjectNotFoundException` | `describeTable()` on a table that doesn't exist |
| `InsufficientPrivilegesException` | User lacks DROP, ALTER, DELETE, or other privilege |
| `ImportFailedException` | Import file has invalid SQL, encoding issues, missing foreign key target |
| `ExportFailedException` | Sink write failure (disk full, permission denied), query fails during export |

## Catching Exceptions: Specific vs Broad

### Catch Specific Exceptions When You Have a Recovery Path

```php
use SQLCraft\Exceptions\UniqueConstraintException;
use SQLCraft\Exceptions\DeadlockException;

try {
    $session->query('INSERT INTO users (email) VALUES (?)', [$email]);
} catch (UniqueConstraintException $e) {
    // Update existing row instead
    $session->query('UPDATE users SET updated_at = NOW() WHERE email = ?', [$email]);
} catch (DeadlockException $e) {
    // Retry with exponential backoff
    $this->retryTransaction();
}
```

### Catch Broad Exception Families for Uniform Handling

```php
use SQLCraft\Exceptions\ConnectionException;
use SQLCraft\Exceptions\QueryException;

try {
    $result = $session->query('SELECT * FROM orders WHERE user_id = ?', [$userId]);
} catch (ConnectionException $e) {
    // Log and fail fast
    $this->logger->critical('Database unreachable', ['host' => $e->host]);
    throw $e;
} catch (QueryException $e) {
    // Log query for debugging
    $this->logger->error('Query failed', ['sql' => $e->sql, 'message' => $e->getMessage()]);
    throw $e;
}
```

### Catch Everything for Blanket Rollback or Observability

```php
use SQLCraft\Exceptions\SQLCraftException;

try {
    $session->connection()->beginTransaction();
    $this->performComplexOperation($session);
    $session->connection()->commit();
} catch (SQLCraftException $e) {
    $session->connection()->rollBack();
    $this->metrics->increment('database_errors', ['type' => $e::class]);
    throw $e;
}
```

## Common Error Handling Patterns

### Retry on Deadlock

```php
function executeWithRetry(DatabaseSession $session, callable $operation, int $maxAttempts = 3): mixed
{
    $attempt = 0;
    while (true) {
        try {
            return $operation($session);
        } catch (DeadlockException $e) {
            $attempt++;
            if ($attempt >= $maxAttempts) {
                throw $e;
            }
            usleep(100_000 * $attempt);  // exponential backoff
        }
    }
}
```

### Graceful Degradation on Missing Capability

```php
use SQLCraft\Capabilities\Capability;
use SQLCraft\Capabilities\CapabilityNotSupportedException;

function createIndexWithFallback(DatabaseSession $session, string $table, string $column): void
{
    $caps = $session->connection()->getPlatform()
        ->getCapabilitySet($session->connection()->getServerVersion());

    try {
        $caps->require(Capability::PartialIndexes);
        $session->ddl()->createIndex("idx_{$table}_{$column}_active")
            ->on($table)
            ->columns([$column])
            ->where('deleted_at IS NULL')
            ->execute($session->connection());
    } catch (CapabilityNotSupportedException) {
        // Fall back to full index
        $session->ddl()->createIndex("idx_{$table}_{$column}")
            ->on($table)
            ->columns([$column])
            ->execute($session->connection());
    }
}
```

### Logging with Context

```php
use Psr\Log\LoggerInterface;
use SQLCraft\Exceptions\QueryException;

function safeQuery(DatabaseSession $session, LoggerInterface $logger, string $sql, array $params): ResultInterface
{
    try {
        return $session->query($sql, $params);
    } catch (QueryException $e) {
        $logger->error('Query execution failed', [
            'sql' => $e->sql,
            'params' => $params,
            'exception' => $e->getMessage(),
        ]);
        throw $e;
    }
}
```

### Connection Pool Cleanup

```php
use SQLCraft\Exceptions\ConnectionLostException;

function queryWithAutoReconnect(DatabaseSession $session, string $sql): ResultInterface
{
    try {
        return $session->query($sql);
    } catch (ConnectionLostException $e) {
        // Remove stale connection from pool and reconnect
        $session->connection()->close();
        $newSession = $this->factory->session($this->params);
        return $newSession->query($sql);
    }
}
```
