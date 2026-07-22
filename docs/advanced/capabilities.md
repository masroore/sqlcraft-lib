# Capabilities System

SQLCraft uses a capabilities system to model the feature differences between database platforms.
Rather than sprinkling `if ($platform === 'mysql')` checks throughout your code, you ask the
active platform whether a feature exists and branch accordingly. This keeps platform-specific
logic in one place and makes your code portable across engines.

## What is a Capability?

A `Capability` is a PHP 8.1+ backed enum whose cases represent discrete database features.
Each case carries a string value used in log messages and exception text.

```php
// src/Capabilities/Capability.php
enum Capability: string
{
    case Table               = 'table';
    case View                = 'view';
    case MaterializedView    = 'materializedview';
    case Sequence            = 'sequence';
    case Type                = 'type';
    case Scheme              = 'scheme';          // named schemas (PostgreSQL, SQL Server)
    case Columns             = 'columns';
    case Comment             = 'comment';
    case Charset             = 'charset';
    case Collation           = 'collation';
    case Compression         = 'compression';
    case GeneratedColumns    = 'generated';
    case Indexes             = 'indexes';
    case ForeignKeys         = 'fkeys';
    case CheckConstraints    = 'check';
    case PartialIndexes      = 'partial_indexes';
    case DescendingIndexes   = 'descidx';
    case Copy                = 'copy';
    case DatabaseManagement  = 'database_management';
    case TableCopy           = 'table_copy';
    case InsertUpdate        = 'insert_update';
    case DropColumn          = 'drop_col';
    case MoveColumn          = 'move_col';
    case Database            = 'database';
    case Routine             = 'routine';
    case Procedure           = 'procedure';
    case Trigger             = 'trigger';
    case ViewTrigger         = 'view_trigger';
    case Event               = 'event';
    case Status              = 'status';
    case Variables           = 'variables';
    case Processlist         = 'processlist';
    case Kill                = 'kill';
    case Privileges          = 'privileges';
    case Sql                 = 'sql';
    case QueryTimeout        = 'query_timeout';
    case UserManagement      = 'user_management';
    case PrivilegeManagement = 'privilege_management';
    case CrossTableSearch    = 'cross_table_search';
    case BlobStreaming        = 'blob_streaming';
    case Dump                = 'dump';
    case Partitions          = 'partitions';
}
```

## Capability Descriptions

| Capability | Meaning |
|---|---|
| `Table` | CREATE / DROP / ALTER TABLE |
| `View` | Regular (non-materialized) views |
| `MaterializedView` | Materialized views (PostgreSQL 9.3+) |
| `Sequence` | Standalone sequence objects |
| `Type` | Custom / composite types |
| `Scheme` | Named schemas separate from databases |
| `Columns` | Column-level introspection |
| `Comment` | Object-level comments (COMMENT ON …) |
| `Charset` | Character-set management |
| `Collation` | Collation listing and assignment |
| `Compression` | Storage compression options |
| `GeneratedColumns` | Virtual / stored generated columns |
| `Indexes` | Index creation and introspection |
| `ForeignKeys` | Foreign-key constraints |
| `CheckConstraints` | CHECK constraints |
| `PartialIndexes` | Filtered / partial indexes |
| `DescendingIndexes` | DESC index columns |
| `Copy` | Platform-level COPY or bulk-load |
| `InsertUpdate` | Upsert (INSERT … ON DUPLICATE KEY / ON CONFLICT) |
| `DropColumn` | ALTER TABLE … DROP COLUMN |
| `MoveColumn` | Column reordering (MySQL FIRST / AFTER) |
| `Routine` | Stored functions |
| `Procedure` | Stored procedures |
| `Trigger` | Table-level triggers |
| `ViewTrigger` | Triggers on views (MariaDB) |
| `Event` | Scheduled events (MySQL / MariaDB) |
| `Status` | Server status variables |
| `Variables` | Runtime variable introspection |
| `Processlist` | Active-process listing |
| `Kill` | Process kill |
| `Privileges` | Privilege introspection |
| `UserManagement` | CREATE / DROP USER |
| `PrivilegeManagement` | GRANT / REVOKE |
| `QueryTimeout` | Statement-level query timeout |
| `CrossTableSearch` | Search across multiple tables |
| `BlobStreaming` | Streaming BLOB reads |
| `Dump` | SQL dump / export |
| `Partitions` | Table partitioning |

## CapabilitySet

`PlatformInterface::getCapabilitySet(ServerVersion $version)` returns a `CapabilitySet` —
an immutable, iterable, countable value object wrapping a list of `Capability|ExtendedCapability`
values.

```php
use SQLCraft\Capabilities\Capability;
use SQLCraft\ValueObjects\ConnectionParameters;

$session = $factory->session(ConnectionParameters::fromDsn('pgsql://localhost/mydb'));
$caps    = $session->connection()->getPlatform()
              ->getCapabilitySet($session->connection()->getServerVersion());

if ($caps->has(Capability::MaterializedView)) {
    // refresh all materialized views
}
```

### `has(Capability|ExtendedCapability): bool`

Returns `true` when the capability is present. Never throws.

```php
$caps->has(Capability::Sequence);        // true on PostgreSQL, false on MySQL
$caps->has(Capability::PartialIndexes);  // true on PostgreSQL and SQLite
```

### `require(Capability|ExtendedCapability): void`

Asserts that a capability is present. Throws `CapabilityNotSupportedException` when it is not.
Also fires a `CapabilityNotSupportedEvent` via the schema event dispatcher so you can observe
capability misses without catching the exception.

```php
// Will throw CapabilityNotSupportedException if not supported
$caps->require(Capability::Sequence);

$session->ddl()->createSequence('order_seq')->execute($session->connection());
```

### `intersect(CapabilitySet): CapabilitySet`

Returns a new `CapabilitySet` containing only capabilities present in both sets.
Useful when you need a common capability baseline across two connections.

```php
$common = $primaryCaps->intersect($replicaCaps);
```

### `toArray(): list<Capability|ExtendedCapability>`

Returns the raw list, useful for serialisation or logging.

### `count(): int` / `getIterator()`

`CapabilitySet` is `Countable` and `IteratorAggregate`, so you can use it directly in
`count()` and `foreach`.

## CapabilityNotSupportedException

Thrown by `CapabilitySet::require()`. It extends `CapabilityException` which extends
`SQLCraftException`.

```php
use SQLCraft\Capabilities\CapabilityNotSupportedException;

final class CapabilityNotSupportedException extends CapabilityException
{
    public readonly Capability|ExtendedCapability $capability;
    public readonly string $platform;
    public readonly string $version;
}
```

Example message: `"Capability not supported: sequence on mysql 8.0.32."`

Catch it when you want to degrade gracefully:

```php
use SQLCraft\Capabilities\CapabilityNotSupportedException;

try {
    $caps->require(Capability::Sequence);
    $session->ddl()->createSequence('invoice_seq')->execute($conn);
} catch (CapabilityNotSupportedException $e) {
    // Fall back to AUTO_INCREMENT column
    $this->logger->info('Sequences not supported on ' . $e->platform . '; using auto-increment.');
}
```

## Per-Platform Capability Matrix

The matrix below reflects the actual `buildCapabilityMatrix()` implementations in the platform
classes. "Always" means available for all supported versions of that engine. "Versioned" lists
the minimum version where the capability first became available.

### MySQL

| Capability | Support |
|---|---|
| Table, View, Columns, Indexes, ForeignKeys, Sql, Database, DropColumn, MoveColumn | Always |
| Dump, Comment, Charset, Collation, Status, Variables, Processlist, Kill | Always |
| Privileges, Trigger, Routine, Procedure, Event, Copy, InsertUpdate | Always |
| Compression, Partitions, CrossTableSearch, BlobStreaming | Always |
| GeneratedColumns | 5.7.0+ |
| DescendingIndexes | 8.0.0+ |
| CheckConstraints | 8.0.16+ |
| Sequence | **Not supported** |
| Scheme, PartialIndexes, MaterializedView | **Not supported** |

### MariaDB

MariaDB inherits MySQL's capability matrix and adds:

| Capability | Support |
|---|---|
| DescendingIndexes | Always (override) |
| GeneratedColumns | 5.2.0+ |
| CheckConstraints | 10.2.1+ |
| Sequence | 10.3.0+ |

### PostgreSQL

| Capability | Support |
|---|---|
| Table, View, Columns, Indexes, ForeignKeys, Sql, Database, DropColumn, Dump | Always |
| Comment, Collation, Processlist, Kill, Trigger, Routine | Always |
| Sequence, Scheme, Type, CheckConstraints, PartialIndexes, DescendingIndexes | Always |
| Partitions, CrossTableSearch, BlobStreaming | Always |
| MaterializedView | 9.3.0+ |
| GeneratedColumns | 12.0.0+ |
| Procedure | 11.0.0+ |
| Charset, Variables, Event | **Not supported** |

### SQLite

| Capability | Support |
|---|---|
| Table, View, Columns, Indexes, ForeignKeys, Sql, Database, DropColumn, Dump | Always |
| Status, Variables, Trigger, CheckConstraints, DescendingIndexes, PartialIndexes | Always |
| InsertUpdate, CrossTableSearch, BlobStreaming | Always |
| GeneratedColumns | 3.31.0+ |
| Scheme, Sequence, Routine, Procedure, Charset, Collation, Processlist | **Not supported** |

### SQL Server (MSSQL)

| Capability | Support |
|---|---|
| Table, View, Columns, Indexes, ForeignKeys, Sql, Database, DropColumn, Dump | Always |
| Comment, Charset, Collation, Status, Variables, Processlist, Kill | Always |
| Privileges, Trigger, ViewTrigger, Routine, Procedure | Always |
| CheckConstraints, DescendingIndexes, GeneratedColumns, Scheme, Type | Always |
| InsertUpdate, CrossTableSearch, BlobStreaming | Always |
| Sequence | 11.0.0+ (SQL Server 2012) |
| PartialIndexes, MaterializedView | **Not supported** |

## ExtendedCapability: Version-Specific or Driver-Specific Features

`ExtendedCapability` is an open-ended named capability not covered by the core enum.
Use it for engine-specific extensions or future capabilities you want to track before
upstreaming them into the enum.

```php
use SQLCraft\Capabilities\ExtendedCapability;

$jsonBinary = new ExtendedCapability('jsonb');

if ($caps->has($jsonBinary)) {
    // use JSONB column type and operators
}
```

Custom drivers can include `ExtendedCapability` instances in their capability matrix:

```php
protected function buildCapabilityMatrix(): array
{
    return [
        'always' => [
            Capability::Table,
            new ExtendedCapability('returning'),   // RETURNING clause
            new ExtendedCapability('listen_notify'),
        ],
        'versioned' => [],
    ];
}
```

## Building Platform-Agnostic Code

The recommended pattern is: check once, branch on the result.

```php
function createOptionalIndex(
    DatabaseSession $session,
    string $table,
    string $column,
    string $where,
): void {
    $caps = $session->connection()->getPlatform()
                ->getCapabilitySet($session->connection()->getServerVersion());

    if ($caps->has(Capability::PartialIndexes)) {
        $session->ddl()
            ->createIndex("idx_{$table}_{$column}_active")
            ->on($table)
            ->columns([$column])
            ->where($where)
            ->execute($session->connection());
    } else {
        // Full index — not as selective, but portable
        $session->ddl()
            ->createIndex("idx_{$table}_{$column}")
            ->on($table)
            ->columns([$column])
            ->execute($session->connection());
    }
}
```

### Capability-Driven Conditionals

Avoid checking platform name directly. Prefer capability checks:

```php
// Avoid this
if ($session->connection()->getPlatformName() === 'pgsql') { ... }

// Prefer this
if ($caps->has(Capability::Sequence)) { ... }
```

Multiple features can be combined with `&&`:

```php
if ($caps->has(Capability::GeneratedColumns) && $caps->has(Capability::CheckConstraints)) {
    // safe to use both in one CREATE TABLE
}
```

## Testing with Mock Capability Sets

In unit tests you can construct a `CapabilitySet` directly with any list you choose:

```php
use SQLCraft\Capabilities\Capability;
use SQLCraft\Capabilities\CapabilitySet;

$limitedCaps = new CapabilitySet([
    Capability::Table,
    Capability::View,
    Capability::Columns,
]);

$mockPlatform = $this->createMock(PlatformInterface::class);
$mockPlatform->method('getCapabilitySet')->willReturn($limitedCaps);
```

To test the `require()` throwing path:

```php
$emptyCaps = new CapabilitySet([]);

$this->expectException(CapabilityNotSupportedException::class);
$emptyCaps->require(Capability::Sequence);
```

To test graceful degradation:

```php
$caps = new CapabilitySet([Capability::Table, Capability::Indexes]);

self::assertFalse($caps->has(Capability::PartialIndexes));
self::assertCount(2, $caps);
```
