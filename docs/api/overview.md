# API Overview

This document describes the public API surface of SQLCraft, its stability guarantees,
and the namespaces you will interact with as a library consumer.

## Public API vs @internal

SQLCraft distinguishes two tiers of code:

- **Public API** — everything not annotated `@internal`. These classes and interfaces follow
  the backward-compatibility promise described below.
- **@internal** — implementation classes that ship in the package but are not part of the
  BC contract. You may use them, but they can change between minor releases without notice.
  Examples: `PdoConnection`, `AbstractPlatform`, `SchemaManagerFactory`, `PlatformCapabilityResolver`.

When in doubt, depend on interfaces from the `Contracts` namespace, not on concrete classes.

## SemVer Policy

SQLCraft follows [Semantic Versioning 2.0](https://semver.org/).

| Change | Version bump |
|---|---|
| New public method on an interface | Major |
| Removed or renamed public method | Major |
| New optional constructor parameter | Minor |
| New class, interface, or enum case | Minor |
| Bug fix with no API change | Patch |
| `@internal` class change | Patch or Minor |

**1.x contracts are frozen.** No interface in the `Contracts` namespace gains a method
without a major version bump. New optional capabilities are added via new interfaces or
via default implementations that you opt into.

## Root Entry Points

There are exactly two objects you construct directly.

### SQLCraftFactory

`SQLCraft\SQLCraftFactory` is the bootstrap class. Construct it once (it is container-friendly)
and call `session()` to open a `DatabaseSession`.

```php
use SQLCraft\Enums\DatabaseDriver;
use SQLCraft\SQLCraftFactory;
use SQLCraft\ValueObjects\ConnectionParameters;

$factory = new SQLCraftFactory(
    drivers:     null,  // uses built-in MySQL, MariaDB, PostgreSQL, SQLite, SQL Server drivers
    credentials: null,  // uses EnvCredentialProvider
    events:      null,  // uses built-in SimpleEventDispatcher
    cache:       null,  // uses NullMetadataCache (no caching)
);

$session = $factory->session(
    new ConnectionParameters(
        host: '127.0.0.1',
        port: 5432,
        database: 'mydb',
        username: 'user',
        password: 'pass',
        driver: DatabaseDriver::PostgreSQL,
    )
);
```

**Constructor parameters (all optional):**

| Parameter | Type | Purpose |
|---|---|---|
| `$drivers` | `DriverRegistry\|null` | Override driver set |
| `$credentials` | `CredentialProviderInterface\|null` | Custom credential resolution |
| `$events` | `EventDispatcherInterface\|null` | PSR-14 dispatcher |
| `$cache` | `MetadataCacheInterface\|null` | Schema metadata cache |

### DatabaseSession

`SQLCraft\DatabaseSession` is the per-connection facade. It is a `readonly` value object —
all state lives in the services it holds. You never construct it yourself; you receive it
from `SQLCraftFactory::session()`.

```php
$session->connection()   // ConnectionInterface
$session->schema()       // SchemaManagerInterface
$session->ddl()          // DdlManager
$session->security()     // SecurityGuardInterface
$session->users()        // UserManagerInterface
$session->privileges()   // PrivilegeManagerInterface
$session->export()       // Exporter
$session->import()       // Importer
$session->query($sql)    // shortcut for executor->query()
```

## Service Modules

### SchemaManagerInterface

`SQLCraft\Contracts\Schema\SchemaManagerInterface` — read-only introspection of the live
database schema. Methods:

- `listDatabases(): DatabaseCollection`
- `listTables(string $database, ?string $schema): TableCollection`
- `listViews(?string $schema): ViewCollection`
- `listRoutines(?string $schema): RoutineCollection`
- `listSequences(?string $schema): SequenceCollection`
- `listTriggers(QualifiedName $table): TriggerCollection`
- `describeTable(QualifiedName $table): TableStructure`
- `listColumns(QualifiedName $table): ColumnCollection`
- `listIndexes(QualifiedName $table): IndexCollection`
- `listForeignKeys(QualifiedName $table): ForeignKeyCollection`
- `listCheckConstraints(QualifiedName $table): CheckConstraintCollection`

### DdlManager

`SQLCraft\DDL\DdlManager` — fluent DDL builder and executor. All builder methods return
a builder object; call `execute(ConnectionInterface)` to run it.

```php
$session->ddl()->createTable('events')
    ->column('id', 'BIGINT')->primaryKey()->autoIncrement()
    ->column('name', 'VARCHAR(255)')->notNull()
    ->execute($session->connection());
```

Notable builders: `createTable`, `alterTable`, `dropTable`, `createIndex`, `dropIndex`,
`createView`, `alterView`, `dropView`, `createSequence`, `dropSequence`,
`createTrigger`, `dropTrigger`, `createRoutine`, `dropRoutine`.

### QueryExecutorInterface

`SQLCraft\Contracts\Execution\QueryExecutorInterface` — execute arbitrary SQL.

```php
$result = $session->query('SELECT * FROM users WHERE active = ?', [1]);

foreach ($result->fetchAll() as $row) {
    // $row is array<string, mixed>
}
```

Also accessible at a lower level via `$session->connection()` directly.

### Exporter

`SQLCraft\Export\Exporter` — exports a database or subset to SQL, CSV, TSV.

```php
use SQLCraft\Export\DumpOptions;
use SQLCraft\Export\DumpScope;
use SQLCraft\Export\ResourceSink;

$sink = new ResourceSink(fopen('/tmp/backup.sql', 'wb'));
$session->export()->dump(DumpOptions::full(), $sink);
```

### Importer

`SQLCraft\Import\Importer` — imports SQL dumps or CSV files.

```php
use SQLCraft\Import\FileImportSource;
use SQLCraft\Import\ImportOptions;

$result = $session->import()->importSql(
    new FileImportSource('/tmp/backup.sql'),
    ImportOptions::default(),
);
```

### SecurityGuardInterface

`SQLCraft\Contracts\Security\SecurityGuardInterface` — validates identifiers and checks
privilege preconditions before query execution.

### UserManagerInterface

`SQLCraft\Contracts\Security\UserManagerInterface` — CREATE / DROP / ALTER USER operations.

### PrivilegeManagerInterface

`SQLCraft\Contracts\Security\PrivilegeManagerInterface` — GRANT / REVOKE / list privileges.

## Namespace Map

| Namespace | Contents | Stability |
|---|---|---|
| `SQLCraft\` (root) | `SQLCraftFactory`, `DatabaseSession` | Public |
| `SQLCraft\Contracts\` | All interfaces | Public, frozen in 1.x |
| `SQLCraft\Capabilities\` | `Capability`, `CapabilitySet`, `ExtendedCapability`, `CapabilityNotSupportedException` | Public |
| `SQLCraft\ValueObjects\` | Immutable value objects | Public |
| `SQLCraft\DTO\` | Data Transfer Objects (read-only structs) | Public |
| `SQLCraft\Collections\` | Typed immutable collections | Public |
| `SQLCraft\Exceptions\` | Exception hierarchy | Public |
| `SQLCraft\Events\` | Event classes and dispatchers | Public (event classes); @internal (dispatchers) |
| `SQLCraft\DDL\` | DdlManager and builder classes | Public (DdlManager); @internal (builders) |
| `SQLCraft\Platform\` | Platform implementations | @internal |
| `SQLCraft\Connection\` | PDO wrappers | @internal |
| `SQLCraft\Metadata\` | Metadata inspector implementations | @internal |
| `SQLCraft\Schema\` | SchemaManager implementation | @internal |
| `SQLCraft\Driver\` | Driver implementations | @internal |
| `SQLCraft\Execution\` | QueryExecutor, BatchExecutor | @internal |
| `SQLCraft\Security\` | Guard implementations | @internal |
| `SQLCraft\Export\` | Exporter implementation | @internal |
| `SQLCraft\Import\` | Importer implementation | @internal |

## Contracts Namespace

`SQLCraft\Contracts\` contains every interface that forms the extension and integration surface:

```
Contracts/
  Capabilities/   CapabilityResolverInterface
  Connection/     ConnectionInterface, ConnectionManagerInterface,
                  CredentialProviderInterface, ResultInterface, PreparedStatementInterface
  DDL/            DdlBuilderInterface, ColumnDefinitionInterface, IndexDefinitionInterface,
                  ForeignKeyDefinitionInterface, CheckConstraintDefinitionInterface,
                  TriggerDefinitionInterface, RoutineParameterDefinitionInterface
  Driver/         DriverInterface
  Events/         ConnectionEventDispatcherInterface, SchemaEventDispatcherInterface,
                  ImportExportEventDispatcherInterface, EventDispatcherAwareInterface
  Execution/      QueryExecutorInterface, BatchExecutorInterface, TransactionManagerInterface,
                  QueryHistoryInterface, ExplainServiceInterface, WarningsProviderInterface
  Export/         ExporterInterface, FormatWriterInterface, SinkInterface, ExportSourceInterface
  Import/         ImporterInterface, FormatReaderInterface, ImportSourceInterface, CsvImporterInterface
  Metadata/       TableInspectorInterface, ColumnInspectorInterface, IndexInspectorInterface,
                  ForeignKeyInspectorInterface, CheckConstraintInspectorInterface,
                  TriggerInspectorInterface, RoutineInspectorInterface, SequenceInspectorInterface,
                  ViewInspectorInterface, DatabaseInspectorInterface, UserInspectorInterface,
                  PrivilegeInspectorInterface, ServerInspectorInterface, MetadataCacheInterface
  Platform/       PlatformInterface, QuotingInterface, PaginationInterface,
                  TypeMapperInterface, DdlDialectInterface, IntrospectionDialectInterface
  Query/          QueryBuilderInterface, PaginatorInterface, TableStatusProviderInterface
  Schema/         SchemaManagerInterface, SchemaInspectorInterface
  Security/       SecurityGuardInterface, UserManagerInterface, PrivilegeManagerInterface
```

## ValueObjects Namespace

Immutable named types that prevent stringly-typed mistakes:

| Class | Purpose |
|---|---|
| `ConnectionParameters` | Host, port, database, credentials, driver (`DatabaseDriver` enum) |
| `Credential` | Username + password pair |
| `Identifier` | A single SQL identifier (table name, column name) |
| `QualifiedName` | Optionally schema- and catalog-qualified name |
| `DataType` | SQL data type with length/precision/scale/flags |
| `DefaultValue` | Column default with kind enum (`EXPRESSION`, `LITERAL`, `NULL_VALUE`) |
| `DefaultValueKind` | Enum: `EXPRESSION`, `LITERAL`, `NULL_VALUE`, `CURRENT_TIMESTAMP` |
| `ServerVersion` | Parsed major.minor.patch version with `isAtLeast()` |
| `Charset` | Character set name |
| `Collation` | Collation name |
| `Engine` | Storage engine string |
| `IndexType` | Enum: BTREE, HASH, GIN, GIST, BRIN, FULLTEXT, SPATIAL |
| `ForeignKeyAction` | Enum: CASCADE, RESTRICT, SET_NULL, NO_ACTION, SET_DEFAULT |
| `Privilege` | Privilege name value object |
| `TriggerEvent` | Enum: INSERT, UPDATE, DELETE |
| `TriggerTiming` | Enum: BEFORE, AFTER, INSTEAD_OF |
| `RoutineDirection` | Enum: IN, OUT, INOUT |

## DTO Namespace

Read-only structs returned by schema introspection. All are `readonly` classes (PHP 8.2+
syntax but compatible with 8.4).

| DTO | Represents |
|---|---|
| `TableStatus` | Row from table listing (name, type, engine, row count, etc.) |
| `ColumnMeta` | Column descriptor (name, data type, nullable, default, auto-increment, comment) |
| `IndexMeta` | Index descriptor (name, type, unique, columns, filter expression) |
| `IndexColumnMeta` | Column within an index (name, descending, expression) |
| `ForeignKeyMeta` | FK descriptor (constraint name, source/target columns, ON DELETE/UPDATE) |
| `BackwardKeyMeta` | Reverse FK reference |
| `CheckConstraintMeta` | CHECK constraint (name, expression, enforced) |
| `TriggerMeta` | Trigger (name, event, timing, body) |
| `RoutineMeta` | Stored routine (name, type, body, parameters) |
| `RoutineParameter` | Routine parameter (name, type, direction) |
| `SequenceMeta` | Sequence (name, start, increment, min, max, cycle) |
| `SchemaMeta` | Schema (name, catalog, owner) |
| `DatabaseMeta` | Database (name, charset, collation) |
| `ViewMeta` | View (name, definition, materialized) |
| `UserMeta` | Database user (name, host, privileges) |
| `ServerInfo` | Server version and platform info |
| `ProcessMeta` | Active connection/process |
| `QueryWarning` | MySQL/MariaDB warning from last statement |
| `ExecutionResult` | Rows affected + last insert ID |
| `ExplainResult` | Raw EXPLAIN output |
| `TableStructure` | Composite: table + columns + indexes + foreign keys + checks |
| `PartitionInfo` | Partition descriptor (name, method, expression, bound) |

## Collections Namespace

All collections extend `AbstractImmutableCollection` which implements `IteratorAggregate`,
`Countable`, and `ArrayAccess`. They are typed, immutable, and support `filter()`, `map()`,
and `first()`.

| Collection | Element type |
|---|---|
| `TableCollection` | `TableStatus` |
| `ColumnCollection` | `ColumnMeta` |
| `IndexCollection` | `IndexMeta` |
| `ForeignKeyCollection` | `ForeignKeyMeta` |
| `CheckConstraintCollection` | `CheckConstraintMeta` |
| `TriggerCollection` | `TriggerMeta` |
| `RoutineCollection` | `RoutineMeta` |
| `SequenceCollection` | `SequenceMeta` |
| `ViewCollection` | `ViewMeta` |
| `SchemaCollection` | `SchemaMeta` |
| `DatabaseCollection` | `DatabaseMeta` |
| `UserCollection` | `UserMeta` |
| `PrivilegeCollection` | `Privilege` |
| `CharsetCollection` | `Charset` |
| `CollationCollection` | `Collation` |
| `TypeCollection` | `string` (type name) |
| `ProcessCollection` | `ProcessMeta` |
| `WarningCollection` | `QueryWarning` |
| `QualifiedNameCollection` | `QualifiedName` |
| `PartitionCollection` | `PartitionInfo` |

## Exceptions Namespace

See `docs/api/exceptions.md` for the full hierarchy. The base class is
`SQLCraft\Exceptions\SQLCraftException extends \RuntimeException`.

Key branches:

- `ConnectionException` — connection-level failures
- `QueryException` — query execution failures
- `ConstraintViolationException` — DB constraint violations
- `CapabilityException` — feature not supported on platform
- `MetadataException` — schema object not found
- `SecurityException` — privilege / access control violations
- `ImportExportException` — import or export failures

## Events Namespace

SQLCraft fires PSR-14 events at key lifecycle points. All events implement
`SQLCraft\Events\SQLCraftEventInterface`.

| Event | Fired when |
|---|---|
| `BeforeConnectionOpened` | Before PDO connect |
| `ConnectionOpenedEvent` | Connection established |
| `ConnectionClosedEvent` | Connection closed |
| `ConnectionFailedEvent` | Connection attempt failed |
| `BeforeQueryExecuted` | Before any SQL execution |
| `AfterQueryExecuted` | After successful query |
| `QueryFailedEvent` | Query threw an exception |
| `SlowQueryDetectedEvent` | Query exceeded slow threshold |
| `BeforeDdlExecuted` | Before DDL statement |
| `AfterDdlExecuted` | After successful DDL |
| `BeforeSchemaChange` | Before schema modification |
| `SchemaChangedEvent` | After schema modification (also triggers cache invalidation) |
| `BeforeTransactionBegan` | Before BEGIN |
| `TransactionBeganEvent` | After BEGIN |
| `TransactionCommittedEvent` | After COMMIT |
| `TransactionRolledBackEvent` | After ROLLBACK |
| `ExportStartedEvent` | Export operation started |
| `ExportProgressEvent` | Export batch progress |
| `ExportWarningEvent` | Non-fatal export warning |
| `ExportFinishedEvent` | Export complete |
| `ImportStartedEvent` | Import operation started |
| `ImportProgressEvent` | Import batch progress |
| `ImportFailedEvent` | Import encountered a fatal error |
| `ImportFinishedEvent` | Import complete |
| `CapabilityNotSupportedEvent` | `CapabilitySet::require()` about to throw |
| `MetadataFetchedEvent` | Schema metadata loaded from DB |
| `ObservabilityEvent` | General observability hook |

## Backward Compatibility Policy

- **Interfaces in `SQLCraft\Contracts\`**: no method additions, removals, or signature changes
  within a major version. Adding a new optional method requires a major version bump.
- **Enums**: new cases may be added in minor releases. Code that switches over enum cases
  must handle the default case.
- **DTOs**: new readonly properties may be added in minor releases (constructors use named
  arguments in the library, but if you new up DTOs directly, add defaults for new fields).
- **Collections**: new collection types added in minor releases.
- **Exception payloads**: new public readonly properties may be added in minor releases.

## Deprecation Process

1. Annotate with `@deprecated since X.Y: use NewClass instead`.
2. Emit a `PSR-3` NOTICE-level log entry via the injected logger when the deprecated code path
   is hit at runtime.
3. Add a `CHANGELOG.md` entry under `Deprecated`.
4. Remove in the next major version.

## @internal Classes that Ship in the Package

These are implementation classes available in the `src/` tree but not BC-covered:

- All classes in `SQLCraft\Platform\` (AbstractPlatform, MySQLPlatform, PostgreSQLPlatform, etc.)
- All classes in `SQLCraft\Connection\` (PdoConnection, PdoConnectionFactory, PdoExceptionTranslator, etc.)
- All classes in `SQLCraft\Metadata\` (MetadataFactory implementations, inspector implementations)
- `SQLCraft\Schema\SchemaManagerFactory`
- `SQLCraft\Schema\CacheInvalidationListener`
- `SQLCraft\Capabilities\PlatformCapabilityResolver`
- All event dispatcher implementations (ConnectionEventDispatcher, SchemaEventDispatcher, etc.)
- All DDL builder classes except `DdlManager` itself
- `SQLCraft\Execution\QueryExecutor`, `BatchExecutor`, `ExplainService`
- `SQLCraft\Security\PrivilegeGuard`, `IdentifierQuoter`, `OperatorValidator`

If you need to extend or replace any of these, implement the relevant `Contracts\` interface
and inject your implementation through the appropriate constructor parameter.
