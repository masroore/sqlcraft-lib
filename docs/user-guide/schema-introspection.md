# Schema Introspection

SQLCraft's schema introspection layer provides a uniform API for reading database metadata across MySQL, PostgreSQL, SQLite, and SQL Server. All introspection goes through `SchemaManagerInterface`, which is returned by `$db->schema()`.

---

## Overview

```php
use SQLCraft\SQLCraftFactory;
use SQLCraft\Enums\DatabaseDriver;
use SQLCraft\ValueObjects\ConnectionParameters;

$factory = new SQLCraftFactory();
$db = $factory->session(new ConnectionParameters(
    host: 'localhost',
    database: 'myapp',
    username: 'root',
    password: 'secret',
    driver: DatabaseDriver::MySQL,
));

$schema = $db->schema(); // SchemaManagerInterface
```

`SchemaManagerInterface` extends `SchemaInspectorInterface` and provides read-only access to every metadata category. All methods accept a `ConnectionInterface` as their first argument and return typed DTOs or typed collections.

---

## Server Information

### `getServerInfo()`

Returns a `ServerInfo` DTO describing the connected server.

```php
use SQLCraft\DTO\ServerInfo;

$info = $schema->getServerInfo($db->connection());

echo $info->platformName;     // "mysql"
echo $info->version;          // ServerVersion object
echo $info->flavor;           // "MariaDB" | null
echo $info->dataDirectory;    // "/var/lib/mysql" | null
echo $info->timezone;         // "+00:00" | null
echo $info->charset;          // "utf8mb4" | null
echo $info->collation;        // "utf8mb4_unicode_ci" | null
```

### `ServerInfo` fields

| Field | Type | Description |
|---|---|---|
| `version` | `ServerVersion` | Parsed server version |
| `platformName` | `string` | Driver name (`mysql`, `pgsql`, `sqlite`, `sqlsrv`) |
| `flavor` | `?string` | `"MariaDB"` where detected; `null` otherwise |
| `dataDirectory` | `?string` | Server data directory path |
| `timezone` | `?string` | Server timezone setting |
| `charset` | `?string` | Default character set |
| `collation` | `?string` | Default collation |

---

## Listing Databases and Schemas

```php
// All databases visible to the current user
$databases = $schema->getDatabases($db->connection());
foreach ($databases as $db_meta) {
    echo $db_meta->name . PHP_EOL;
}

// Schemas within the current database (PostgreSQL / SQL Server)
$schemas = $schema->getSchemas($db->connection());
foreach ($schemas as $schemaMeta) {
    echo $schemaMeta->name . PHP_EOL;
}
```

On MySQL/SQLite, `getSchemas()` returns an empty collection because these engines do not have a schema layer between databases and tables.

---

## Listing and Describing Tables

### `getTables()`

```php
use SQLCraft\Collections\TableCollection;

$tables = $schema->getTables($db->connection());          // current database
$tables = $schema->getTables($db->connection(), 'public'); // specific schema (PostgreSQL)

foreach ($tables as $tableStatus) {
    echo $tableStatus->name . ' (' . $tableStatus->engine . ')' . PHP_EOL;
}
```

### `describeTable()`

Returns a `TableStructure` aggregate containing columns, indexes, foreign keys, and triggers in a single call.

```php
use SQLCraft\ValueObjects\QualifiedName;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\Schema\TableStructure;

$table = new QualifiedName(new Identifier('orders'));

$structure = $schema->describeTable($db->connection(), $table);

// TableStructure fields:
$structure->status;      // TableStatus DTO
$structure->columns;     // ColumnCollection
$structure->indexes;     // IndexCollection
$structure->foreignKeys; // ForeignKeyCollection
$structure->triggers;    // TriggerCollection
```

For PostgreSQL with an explicit schema:

```php
$table = new QualifiedName(
    new Identifier('orders'),
    new Identifier('public'),
);
$structure = $schema->describeTable($db->connection(), $table);
```

---

## Column Metadata

### `getColumns()`

```php
$columns = $schema->getColumns($db->connection(), $table);

foreach ($columns as $col) {
    printf(
        "%s %s %s\n",
        $col->name,
        $col->dataType->name,
        $col->nullable ? 'NULL' : 'NOT NULL',
    );
}
```

### `getColumn()`

Fetch a single column by name:

```php
$col = $schema->getColumn(
    $db->connection(),
    $table,
    new Identifier('email'),
);
```

### `ColumnMeta` fields

| Field | Type | Description |
|---|---|---|
| `name` | `string` | Column name |
| `dataType` | `DataType` | Type name, length, precision, scale, unsigned |
| `nullable` | `bool` | Whether `NULL` is allowed |
| `autoIncrement` | `bool` | Auto-increment / serial |
| `primary` | `bool` | Part of primary key |
| `generated` | `bool` | Computed / generated column |
| `default` | `DefaultValue` | Default value with kind enum |
| `collation` | `?Collation` | Column-level collation |
| `comment` | `?string` | Column comment |
| `onUpdate` | `?string` | `ON UPDATE` expression (MySQL) |
| `privileges` | `list<int>` | Column-level privilege bitmask |
| `origName` | `?string` | Original name before rename |
| `defaultConstraintName` | `?string` | Named default constraint (SQL Server) |

---

## Index Metadata

```php
$indexes = $schema->getIndexes($db->connection(), $table);

foreach ($indexes as $index) {
    echo $index->name . ' [' . $index->type->value . ']' . PHP_EOL;
    foreach ($index->columns as $col) {
        echo '  ' . $col->name . ($col->descending ? ' DESC' : '') . PHP_EOL;
    }
}
```

### `IndexMeta` fields

| Field | Type | Description |
|---|---|---|
| `name` | `string` | Index name |
| `type` | `IndexType` | `PRIMARY`, `UNIQUE`, `INDEX`, `FULLTEXT`, `SPATIAL` |
| `columns` | `list<IndexColumnMeta>` | Ordered column list with direction and length |
| `unique` | `bool` | Whether the index enforces uniqueness |
| `comment` | `?string` | Index comment |
| `algorithm` | `?string` | Storage algorithm (`BTREE`, `HASH`) |
| `filterExpression` | `?string` | Partial index filter (PostgreSQL / SQL Server) |

### `IndexColumnMeta` fields

| Field | Type | Description |
|---|---|---|
| `name` | `string` | Column name |
| `length` | `?int` | Prefix length |
| `descending` | `bool` | Descending sort order |
| `expression` | `?string` | Expression for functional indexes |

---

## Foreign Key Metadata

```php
$fks = $schema->getForeignKeys($db->connection(), $table);

foreach ($fks as $fk) {
    printf(
        "%s -> %s.%s (%s) ON DELETE %s\n",
        implode(',', $fk->sourceColumns),
        $fk->targetTable,
        implode(',', $fk->targetColumns),
        $fk->constraintName,
        $fk->onDelete->value,
    );
}

// Reverse: find tables that reference $table
$refs = $schema->getReferencingKeys($db->connection(), $table);
```

### `ForeignKeyMeta` fields

| Field | Type | Description |
|---|---|---|
| `constraintName` | `string` | Constraint name |
| `targetDatabase` | `?string` | Target database (cross-database FKs) |
| `targetSchema` | `?string` | Target schema |
| `targetTable` | `string` | Referenced table name |
| `sourceColumns` | `list<string>` | Columns in the child table |
| `targetColumns` | `list<string>` | Columns in the parent table |
| `onDelete` | `ForeignKeyAction` | `CASCADE`, `SET NULL`, `RESTRICT`, `NO ACTION` |
| `onUpdate` | `ForeignKeyAction` | Same options as `onDelete` |
| `definition` | `?string` | Raw DDL fragment |
| `deferrable` | `bool` | PostgreSQL deferrable constraint |

---

## Trigger Metadata

```php
$triggers = $schema->getTriggers($db->connection(), $table);

foreach ($triggers as $trigger) {
    printf(
        "%s %s %s\n",
        $trigger->timing->value,   // BEFORE / AFTER
        $trigger->event->value,    // INSERT / UPDATE / DELETE
        $trigger->name,
    );
}
```

### `TriggerMeta` fields

| Field | Type | Description |
|---|---|---|
| `name` | `string` | Trigger name |
| `timing` | `TriggerTiming` | `BEFORE` or `AFTER` |
| `event` | `TriggerEvent` | `INSERT`, `UPDATE`, or `DELETE` |
| `body` | `string` | Trigger body SQL |
| `definer` | `?string` | Definer user |
| `table` | `?string` | Associated table name |

---

## View Metadata

```php
$views = $schema->getViews($db->connection());

foreach ($views as $view) {
    echo $view->name . ($view->materialized ? ' [materialized]' : '') . PHP_EOL;
}

// Get the defining SQL for a specific view
$qn = new QualifiedName(new Identifier('active_users'));
$sql = $schema->getViewDefinition($db->connection(), $qn);
```

### `ViewMeta` fields

| Field | Type | Description |
|---|---|---|
| `name` | `string` | View name |
| `schema` | `?string` | Owning schema |
| `definition` | `?string` | SELECT statement |
| `materialized` | `bool` | `true` for PostgreSQL materialized views |

PostgreSQL materialized views are returned separately via `getMaterializedViews()`.

---

## Routine Metadata

```php
$functions  = $schema->getFunctions($db->connection());
$procedures = $schema->getProcedures($db->connection());

$detail = $schema->getRoutineDetail(
    $db->connection(),
    new QualifiedName(new Identifier('calculate_discount')),
);
```

### `RoutineMeta` fields

| Field | Type | Description |
|---|---|---|
| `name` | `string` | Routine name |
| `type` | `string` | `FUNCTION` or `PROCEDURE` |
| `params` | `list<RoutineParameter>` | Parameter definitions |
| `returnType` | `?DataType` | Return type (functions only) |
| `body` | `string` | Routine body |
| `language` | `?string` | Implementation language (`SQL`, `plpgsql`) |
| `comment` | `?string` | Routine comment |
| `definer` | `string` | Definer user |
| `deterministic` | `bool` | Whether result is deterministic |
| `sqlDataAccess` | `string` | `READS SQL DATA`, `MODIFIES SQL DATA`, etc. |

---

## Capability-Gated Inspectors

Some inspectors only work on platforms that support the feature. Calling them on an unsupported platform throws `CapabilityNotSupportedException`.

```php
use SQLCraft\Capabilities\Capability;
use SQLCraft\Capabilities\CapabilityNotSupportedException;

$caps = $db->connection()->getPlatform()->getCapabilities();

if ($caps->has(Capability::Sequence)) {
    $sequences = $schema->getSequences($db->connection());
}

// Or let the exception propagate and handle it:
try {
    $sequences = $schema->getSequences($db->connection());
} catch (CapabilityNotSupportedException $e) {
    // MySQL does not support sequences; handle gracefully
}
```

Capability availability by platform:

| Feature | MySQL | PostgreSQL | SQLite | SQL Server |
|---|---|---|---|---|
| `Sequence` | No | Yes | No | Yes |
| `MaterializedView` | No | Yes | No | No |
| `CheckConstraints` | Yes (8.0+) | Yes | Yes | Yes |
| `Trigger` | Yes | Yes | Yes | Yes |
| `Routine` | Yes | Yes | No | Yes |
| `Partitions` | Yes | Yes | No | No |

---

## Metadata Caching

By default, `SQLCraftFactory` uses `NullMetadataCache` (no caching). For production, wrap a PSR-16 cache:

```php
use SQLCraft\Schema\Psr16MetadataCache;
use SQLCraft\SQLCraftFactory;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Psr16Cache;

$psr16 = new Psr16Cache(new RedisAdapter(/* ... */));
$cache = new Psr16MetadataCache($psr16, prefix: 'sqlcraft:');

$factory = new SQLCraftFactory(cache: $cache);
$db = $factory->session($params);
```

For lightweight in-process caching (e.g., within a single request):

```php
use SQLCraft\Schema\InMemoryMetadataCache;

$factory = new SQLCraftFactory(cache: new InMemoryMetadataCache());
```

Cache keys follow the pattern `{platform}/{database}/{method}:{qualifier}`. After a DDL operation, `CacheInvalidationListener` automatically flushes the cache by calling `clear()`.

---

## Comparing Schemas

`compare()` does a deep equality check between two metadata values and returns a diff map. `describeDiff()` serialises that map to a JSON string.

```php
$expected = $schema->getColumns($conn, $tableA);
$actual   = $schema->getColumns($conn, $tableB);

$diff = $schema->compare($expected, $actual);

if ($diff !== []) {
    echo $schema->describeDiff($diff);
    // {"expected": ..., "actual": ...}
}
```

This is useful in migration tests to assert that a schema matches a known snapshot.

---

## Golden-File Pattern for Introspection SQL

A common technique for testing introspection queries is to capture query output once (the "golden file") and compare on subsequent runs:

```php
// tests/Schema/GoldenTest.php
$actual = $schema->getColumns($conn, new QualifiedName(new Identifier('orders')));
$serialised = json_encode($actual, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

$goldenPath = __DIR__ . '/fixtures/orders_columns.json';
if (!file_exists($goldenPath)) {
    file_put_contents($goldenPath, $serialised); // first run: create golden file
} else {
    $this->assertJsonStringEqualsJsonFile($goldenPath, $serialised);
}
```

Regenerate golden files after intentional schema changes by deleting the fixture and re-running.

---

## Platform-Specific Examples

### MySQL

```php
// MySQL-specific: table status with engine and row count
$status = $schema->getTableStatus($conn, new QualifiedName(new Identifier('orders')));
echo $status->engine;    // "InnoDB"
echo $status->rowCount;  // approximate from information_schema

// Full-text indexes
$indexes = $schema->getIndexes($conn, $table);
foreach ($indexes as $idx) {
    if ($idx->type === \SQLCraft\ValueObjects\IndexType::FULLTEXT) {
        echo 'FULLTEXT: ' . $idx->name . PHP_EOL;
    }
}
```

### PostgreSQL

```php
// Schema-qualified table
$table = new QualifiedName(new Identifier('orders'), new Identifier('billing'));
$cols  = $schema->getColumns($conn, $table);

// Sequences
$seqs = $schema->getSequences($conn, schema: 'public');

// Materialized views
$matViews = $schema->getMaterializedViews($conn, schema: 'analytics');
```

### SQLite

```php
// SQLite has no schema layer; pass null
$tables = $schema->getTables($conn, null);

// SQLite does not support sequences or routines
$caps = $conn->getPlatform()->getCapabilities();
echo $caps->has(Capability::Sequence) ? 'yes' : 'no'; // no
```

### SQL Server

```php
// Three-part name: catalog.schema.table
$table = new QualifiedName(
    new Identifier('orders'),
    new Identifier('dbo'),
    new Identifier('mydb'),
);
$structure = $schema->describeTable($conn, $table);
```

---

## Best Practices

- Prefer `describeTable()` over calling `getColumns()`, `getIndexes()`, `getForeignKeys()`, and `getTriggers()` separately — it batches and caches all four in one shot.
- Always check `Capability::has()` before calling capability-gated methods in code that must run across multiple platforms.
- Use `InMemoryMetadataCache` for single-request scenarios and `Psr16MetadataCache` backed by Redis or Memcached for shared, multi-process environments.
- Treat `getReferencingKeys()` as expensive on large schemas; cache the result or call it lazily.
- When streaming large table lists, use `streamTables()` instead of `getTables()` to avoid loading all `TableStatus` objects into memory.
