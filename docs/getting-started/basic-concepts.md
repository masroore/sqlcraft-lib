# Basic Concepts

This guide introduces SQLCraft's core concepts and architecture.

## Architecture Overview

SQLCraft follows a hexagonal (ports and adapters) architecture with clear boundaries:

```
┌──────────────────────────────────────────────────────────────┐
│                    Your Application                           │
│  (Framework controllers, CLI commands, AI tools, REST APIs)  │
└────────────────────────┬─────────────────────────────────────┘
                         │
┌────────────────────────▼─────────────────────────────────────┐
│               SQLCraftFactory / DatabaseSession               │
│                   (Composition Root)                          │
└──┬──────┬──────┬──────┬───────┬───────────┬─────────────────┘
   │      │      │      │       │           │
   │      │      │      │       │           │
┌──▼──┐ ┌─▼──┐ ┌─▼──┐ ┌▼────┐ ┌▼────────┐ ┌▼──────────────┐
│Query│ │Meta│ │ DDL│ │Exec │ │Import/  │ │Security/Users │
│     │ │data│ │    │ │     │ │Export   │ │Privileges     │
└──┬──┘ └─┬──┘ └─┬──┘ └─┬───┘ └─┬───────┘ └──────┬────────┘
   │      │      │      │       │                  │
┌──▼──────▼──────▼──────▼───────▼──────────────────▼────────┐
│                Platform / Driver Layer                      │
│   MySQL │ MariaDB │ PostgreSQL │ SQLite │ SQL Server       │
└────────────────────────┬────────────────────────────────────┘
                         │
┌────────────────────────▼────────────────────────────────────┐
│                 Connection Layer                             │
│          ConnectionInterface → PDO (isolated)                │
└─────────────────────────────────────────────────────────────┘
```

## Core Components

### 1. SQLCraftFactory

The **factory** is your entry point. It creates database sessions:

```php
use SQLCraft\SQLCraftFactory;

$factory = new SQLCraftFactory();
$db = $factory->session($connectionParams);
```

The factory:
- Manages driver registry (MySQL, PostgreSQL, SQLite, SQL Server)
- Wires up event dispatchers, caches, and loggers
- Creates isolated database sessions
- No global state

### 2. DatabaseSession

A **session** represents a single database connection with all its services:

```php
$db->schema();      // Schema introspection
$db->ddl();         // DDL operations (CREATE, ALTER, DROP)
$db->query($sql);   // Query execution
$db->export();      // Export data
$db->import();      // Import data
$db->security();    // Security guards
$db->users();       // User management
$db->privileges();  // Privilege management
$db->connection();  // Raw connection (escape hatch)
```

Sessions are:
- **Immutable** - safe to pass around
- **Isolated** - no shared state between sessions
- **Framework-agnostic** - works anywhere

### 3. Connection Layer

The **connection** wraps PDO and provides:
- Transaction management
- Query execution
- Result streaming/buffering
- Exception translation

You rarely interact with the connection directly—services use it internally.

```php
// Direct connection usage (rare)
$connection = $db->connection();
$connection->beginTransaction();
// ... operations ...
$connection->commit();
```

### 4. Platform Layer

The **platform** encapsulates database-specific behavior:
- SQL dialect differences
- Quoting and escaping
- Type mapping
- Capability detection
- DDL generation

```php
$platform = $db->connection()->getPlatform();
$platform->getName(); // "mysql", "pgsql", "sqlite", etc.
$platform->quoteIdentifier('table'); // `table` on MySQL, "table" on PostgreSQL
```

## Key Design Principles

### No Global State

Unlike traditional tools, SQLCraft has zero global variables or singletons:

```php
// Create multiple independent sessions
$mysql = $factory->session($mysqlParams);
$pgsql = $factory->session($pgsqlParams);

// They don't interfere with each other
$mysql->query('SELECT 1');
$pgsql->query('SELECT 1');
```

### Typed All The Way

No `array` returns or loose types. Everything is strongly typed:

```php
// Returns TableCollection<TableStatus>
$tables = $db->schema()->listTables();

// Each table is a typed DTO
foreach ($tables as $table) {
    $table->name;        // string
    $table->engine;      // ?string
    $table->rows;        // ?int
    $table->autoIncrement; // ?int
}
```

### Immutable Value Objects

Once created, value objects never change:

```php
use SQLCraft\ValueObjects\DataType;

$int = DataType::int();
$bigint = $int->withPrecision(20); // Returns NEW instance

// Original is unchanged
echo $int->name; // "INT"
echo $bigint->name; // "BIGINT"
```

### Engine-Independent Code

Write once, run on any supported database:

```php
function countOrders(DatabaseSession $db): int {
    $result = $db->query('SELECT COUNT(*) as count FROM orders');
    return (int) $result->fetchColumn('count');
}

// Works with any database
countOrders($mysql);
countOrders($pgsql);
countOrders($sqlite);
```

## Value Objects vs DTOs

### Value Objects (VOs)

**Value Objects** are immutable, self-validating domain primitives:

- `Identifier` - A table/column/index name
- `QualifiedName` - Database.schema.table
- `DataType` - Column type with precision/scale
- `ConnectionParameters` - Connection configuration

```php
use SQLCraft\ValueObjects\Identifier;

$id = new Identifier('users'); // Validates on construction
$id->value; // "users"

// Invalid identifier throws immediately
new Identifier(''); // Exception: empty identifier
new Identifier("table\0name"); // Exception: null byte
```

### Data Transfer Objects (DTOs)

**DTOs** are immutable data containers returned from operations:

- `TableStatus` - Table metadata (name, engine, rows, etc.)
- `ColumnMeta` - Column definition
- `IndexMeta` - Index definition
- `ForeignKeyMeta` - Foreign key constraint
- `ServerInfo` - Server version and capabilities

```php
$structure = $db->schema()->describeTable('orders');

// All fields are readonly
$structure->columns;      // ColumnCollection
$structure->indexes;      // IndexCollection
$structure->foreignKeys;  // ForeignKeyCollection
$structure->triggers;     // TriggerCollection
```

## Collections

SQLCraft uses **typed collections** instead of arrays:

```php
$tables = $db->schema()->listTables(); // TableCollection

// Implements IteratorAggregate
foreach ($tables as $table) {
    echo $table->name;
}

// Functional operations
$large = $tables->filter(fn($t) => $t->rows > 1000);
$names = $tables->map(fn($t) => $t->name);
$first = $tables->first();
$total = $tables->count();
```

Benefits:
- IDE autocomplete knows the element type
- No need for `@var` annotations
- Functional programming patterns
- Immutable by default

## The Capability System

Different databases support different features. Check before using:

```php
use SQLCraft\Capabilities\Capability;

$caps = $db->connection()->getPlatform()->getCapabilities();

// Check if supported
if ($caps->has(Capability::Trigger)) {
    // Create trigger
}

// Require or throw
$caps->require(Capability::CheckConstraints); // Throws if unavailable
```

Common capabilities:
- `Trigger` - Triggers
- `StoredProcedure` - Stored procedures
- `Sequences` - Sequence objects
- `CheckConstraints` - CHECK constraints
- `PartialIndexes` - Partial/filtered indexes
- `Schemas` - Named schemas (PostgreSQL, SQL Server)

## Event System

SQLCraft dispatches events for observability:

```php
use Psr\EventDispatcher\EventDispatcherInterface;
use SQLCraft\Events\AfterQueryExecuted;

class QueryLogger {
    public function __invoke(AfterQueryExecuted $event): void {
        $duration = $event->duration;
        $sql = $event->sql;
        error_log("Query took {$duration}ms: {$sql}");
    }
}

$dispatcher = // ... your PSR-14 dispatcher
$dispatcher->addListener(AfterQueryExecuted::class, new QueryLogger());

$factory = new SQLCraftFactory(events: $dispatcher);
```

Available events:
- Connection lifecycle (opened, closed)
- Query execution (before/after)
- DDL execution (before/after)
- Schema changes
- Import/export progress
- Transaction lifecycle

## Exception Hierarchy

All SQLCraft exceptions extend `SQLCraftException`:

```php
use SQLCraft\Exceptions\{
    SQLCraftException,
    ConnectionException,
    QueryException,
    ConstraintViolationException,
    UniqueConstraintException,
    ForeignKeyConstraintException
};

try {
    $db->query($sql);
} catch (UniqueConstraintException $e) {
    // Duplicate key
    echo $e->constraintName;
} catch (QueryException $e) {
    // Any other query error
    echo $e->sql;
} catch (ConnectionException $e) {
    // Connection lost
}
```

Benefits:
- Type-safe exception handling
- No parsing error messages
- Typed payloads (constraint name, SQL, etc.)
- Consistent across all databases

## Streaming vs Buffering

By default, results are **streamed** to save memory:

```php
// Streams row-by-row (constant memory)
$result = $db->query('SELECT * FROM huge_table');
foreach ($result as $row) {
    // Process one row at a time
}
```

Use **buffered** mode when you need the full result set:

```php
// Loads all rows into memory
$result = $db->query('SELECT * FROM small_table');
$rows = iterator_to_array($result);

// Now you can iterate multiple times
foreach ($rows as $row) { }
foreach ($rows as $row) { }
```

## Transaction Management

Transactions provide ACID guarantees:

```php
// Automatic rollback on exception
$db->connection()->beginTransaction();
try {
    $db->query('INSERT INTO orders ...');
    $db->query('UPDATE inventory ...');
    $db->connection()->commit();
} catch (\Exception $e) {
    $db->connection()->rollback();
    throw $e;
}
```

## Best Practices

### 1. Use Parameter Binding

Always use parameters, never concatenate:

```php
// ✅ Good - parameterized
$db->query('SELECT * FROM users WHERE email = ?', [$email]);

// ❌ Bad - SQL injection risk
$db->query("SELECT * FROM users WHERE email = '$email'");
```

### 2. Check Capabilities

Don't assume features are available:

```php
// ✅ Good - check first
if ($caps->has(Capability::Sequences)) {
    // Use sequences
} else {
    // Use alternative
}

// ❌ Bad - assumes support
$db->query('CREATE SEQUENCE ...');
```

### 3. Use Typed DTOs

Leverage type safety:

```php
// ✅ Good - typed access
$structure = $db->schema()->describeTable('users');
foreach ($structure->columns as $column) {
    echo $column->name;
}

// ❌ Bad - manual introspection
$result = $db->query('SHOW COLUMNS FROM users');
```

### 4. Handle Exceptions Specifically

Catch specific exceptions:

```php
// ✅ Good - specific handling
try {
    $db->query($sql);
} catch (UniqueConstraintException $e) {
    // Handle duplicate
} catch (QueryException $e) {
    // Handle other errors
}

// ❌ Bad - generic catch
try {
    $db->query($sql);
} catch (\Exception $e) {
    // Can't distinguish errors
}
```

## Next Steps

- [Schema Introspection](../user-guide/schema-introspection.md) - Learn to inspect database structure
- [DDL Operations](../user-guide/ddl-operations.md) - Create and modify schemas
- [Query Execution](../user-guide/query-execution.md) - Execute queries effectively
- [Capabilities System](../advanced/capabilities.md) - Deep dive into capabilities
