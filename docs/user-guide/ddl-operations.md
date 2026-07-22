# DDL Operations

SQLCraft provides a fluent, immutable builder API for every DDL operation. All builders produce parameterised, platform-specific SQL via `toSql()` and execute through `DdlManager`, which fires events and handles SQLite's table-recreation strategy automatically.

---

## The `DdlManager` Facade

`$db->ddl()` returns a `DdlManager` instance. It has two public methods:

```php
$ddl = $db->ddl();

// Preview the SQL that would be executed (returns list<string>)
$statements = $ddl->preview($db->connection(), $builder);

// Execute the DDL statements
$ddl->execute($db->connection(), $builder);
```

`execute()` dispatches:
- `BeforeDdlExecuted` — cancellable; throw `OperationCancelledException` to abort
- `AfterDdlExecuted` — fires after each statement; triggers cache invalidation
- `BeforeSchemaChange` / `SchemaChangedEvent` — broader schema-change lifecycle events

Any listener that returns a non-null cancel reason from `BeforeDdlExecuted` will cause `OperationCancelledException` to be thrown before the statement runs.

---

## Value Objects Used in DDL

### `Identifier`

Wraps a raw name. Validated at construction — empty strings and null-byte names are rejected.

```php
use SQLCraft\ValueObjects\Identifier;

$id = new Identifier('users');
echo (string) $id; // "users"
```

### `QualifiedName`

Represents `catalog.schema.object` or any subset.

```php
use SQLCraft\ValueObjects\QualifiedName;

$unqualified  = new QualifiedName(new Identifier('orders'));
$schemaScoped = new QualifiedName(new Identifier('orders'), new Identifier('public'));
$fullQualified = new QualifiedName(
    new Identifier('orders'),
    new Identifier('dbo'),
    new Identifier('mydb'),
);
```

### `DataType`

Carries the type name, length, precision, scale, and unsigned flag. Construction validates that the name is a safe SQL identifier.

```php
use SQLCraft\ValueObjects\DataType;

$int      = new DataType('int');
$varchar  = new DataType('varchar', length: 255);
$decimal  = new DataType('decimal', precision: 10, scale: 2);
$bigint   = new DataType('bigint', unsigned: true);
$text     = new DataType('text');
$bool     = new DataType('tinyint', length: 1);
$ts       = new DataType('timestamp');
$uuid     = new DataType('char', length: 36);
$jsonType = new DataType('json');
```

### `DefaultValue` and `DefaultValueKind`

```php
use SQLCraft\ValueObjects\DefaultValue;
use SQLCraft\ValueObjects\DefaultValueKind;

DefaultValue::none();                          // no default
DefaultValue::null();                          // DEFAULT NULL
DefaultValue::literal('0');                    // DEFAULT '0'
DefaultValue::expression('CURRENT_TIMESTAMP'); // DEFAULT CURRENT_TIMESTAMP
```

---

## `ColumnDefinition`

All DDL builders consume `ColumnDefinitionInterface`. Use the concrete `ColumnDefinition`:

```php
use SQLCraft\DDL\Definition\ColumnDefinition;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\DefaultValue;

$idCol = new ColumnDefinition(
    name: 'id',
    dataType: new DataType('bigint', unsigned: true),
    nullable: false,
    autoIncrement: true,
    primary: true,
    generated: false,
    default: DefaultValue::none(),
    collation: null,
    comment: 'Primary key',
    onUpdate: null,
    privileges: [],
    originalName: null,
    defaultConstraintName: null,
);

$emailCol = new ColumnDefinition(
    name: 'email',
    dataType: new DataType('varchar', length: 255),
    nullable: false,
    autoIncrement: false,
    primary: false,
    generated: false,
    default: DefaultValue::none(),
    collation: null,
    comment: null,
    onUpdate: null,
    privileges: [],
    originalName: null,
    defaultConstraintName: null,
);
```

---

## `IndexDefinition`

```php
use SQLCraft\DDL\Definition\IndexDefinition;
use SQLCraft\DDL\Definition\IndexColumnDefinition;
use SQLCraft\ValueObjects\IndexType;

$pk = new IndexDefinition(
    name: 'PRIMARY',
    type: IndexType::PRIMARY,
    columns: [new IndexColumnDefinition('id')],
    unique: true,
    comment: null,
    algorithm: 'BTREE',
    filterExpression: null,
);

$emailIdx = new IndexDefinition(
    name: 'uq_users_email',
    type: IndexType::UNIQUE,
    columns: [new IndexColumnDefinition('email')],
    unique: true,
    comment: null,
    algorithm: null,
    filterExpression: null,
);
```

---

## `ForeignKeyDefinition`

```php
use SQLCraft\DDL\Definition\ForeignKeyDefinition;
use SQLCraft\ValueObjects\ForeignKeyAction;

$fk = new ForeignKeyDefinition(
    constraintName: 'fk_orders_user_id',
    targetDatabase: null,
    targetSchema: null,
    targetTable: 'users',
    sourceColumns: ['user_id'],
    targetColumns: ['id'],
    onDelete: ForeignKeyAction::Cascade,
    onUpdate: ForeignKeyAction::Restrict,
    definition: null,
    deferrable: false,
);
```

---

## `CreateTableBuilder`

Immutable; every `with*()` method returns a new instance.

```php
use SQLCraft\DDL\CreateTableBuilder;
use SQLCraft\ValueObjects\QualifiedName;
use SQLCraft\ValueObjects\Identifier;

$builder = new CreateTableBuilder(
    table: new QualifiedName(new Identifier('orders')),
    ifNotExists: true,
    engine: 'InnoDB',
    charset: 'utf8mb4',
    collation: 'utf8mb4_unicode_ci',
    comment: 'Customer orders',
);

$builder = $builder
    ->withColumn($idCol)
    ->withColumn($emailCol)
    ->withIndex($pk)
    ->withIndex($emailIdx)
    ->withForeignKey($fk);

// Preview
$sql = $db->ddl()->preview($db->connection(), $builder);
// ["CREATE TABLE `orders` (...)"]

// Execute
$db->ddl()->execute($db->connection(), $builder);
```

### `CreateTableBuilder` constructor options

| Parameter | Type | Default | Description |
|---|---|---|---|
| `table` | `QualifiedName` | required | Table name |
| `columns` | `list<ColumnDefinitionInterface>` | `[]` | Column list |
| `indexes` | `list<IndexDefinitionInterface>` | `[]` | Index list |
| `foreignKeys` | `list<ForeignKeyDefinitionInterface>` | `[]` | FK constraints |
| `checkConstraints` | `list<CheckConstraintDefinitionInterface>` | `[]` | Check constraints |
| `engine` | `?string` | `null` | Storage engine (MySQL only) |
| `charset` | `?string` | `null` | Character set (MySQL only) |
| `collation` | `?string` | `null` | Collation (MySQL only) |
| `comment` | `?string` | `null` | Table comment |
| `ifNotExists` | `bool` | `false` | Add `IF NOT EXISTS` guard |
| `temporary` | `bool` | `false` | Create as `TEMPORARY` table |
| `includeAutoIncrementValue` | `bool` | `false` | Preserve current auto-increment seed |
| `autoIncrementValue` | `?int` | `null` | Explicit seed value |

---

## `AlterTableBuilder`

Supports adding columns, modifying columns, dropping columns, adding/dropping indexes, adding/dropping foreign keys, and renaming.

```php
use SQLCraft\DDL\AlterTableBuilder;

$alter = new AlterTableBuilder(
    table: new QualifiedName(new Identifier('orders')),
);

// Add a column (optionally after another column)
$alter = $alter->withColumn($newCol, after: new Identifier('email'));

// Modify a column (requires both new and original definitions)
$alter = $alter->modifyColumn($newEmailCol, $originalEmailCol);

// Drop a column
$alter = $alter->dropColumn(new Identifier('legacy_field'));

// Add an index
$alter = $alter->withIndex($emailIdx);

// Drop an index
$alter = $alter->dropIndex(new Identifier('idx_old'));

// Add a foreign key
$alter = $alter->withForeignKey($fk);

// Drop a foreign key
$alter = $alter->dropForeignKey(new Identifier('fk_orders_user_id'));

// Rename the table
$alter = $alter->renameTo(new Identifier('customer_orders'));

$db->ddl()->execute($db->connection(), $alter);
```

### SQLite Table-Recreation Strategy

SQLite does not support `ALTER TABLE ... DROP COLUMN` or `ALTER TABLE ... MODIFY COLUMN` natively. When `execute()` is called with an `AlterTableBuilder` against a SQLite connection, `DdlManager` automatically delegates to `TableRecreationStrategy`, which:

1. Opens a transaction
2. Creates a new temporary table with the desired structure
3. Copies data from the original table
4. Drops the original
5. Renames the temporary table to the original name
6. Re-creates any indexes and triggers

This is transparent — the same `AlterTableBuilder` works on all platforms.

---

## `DropTableBuilder`

```php
use SQLCraft\DDL\DropTableBuilder;

$builder = new DropTableBuilder(
    table: new QualifiedName(new Identifier('orders')),
    ifExists: true,
    cascade: false, // PostgreSQL CASCADE
);

$db->ddl()->execute($db->connection(), $builder);
```

---

## `TruncateBuilder`

```php
use SQLCraft\DDL\TruncateBuilder;

$builder = new TruncateBuilder(
    table: new QualifiedName(new Identifier('orders')),
    cascade: false,          // PostgreSQL: TRUNCATE ... CASCADE
    restartIdentity: true,   // PostgreSQL: RESTART IDENTITY
);

$db->ddl()->execute($db->connection(), $builder);
```

---

## `CreateIndexBuilder` and `DropIndexBuilder`

```php
use SQLCraft\DDL\CreateIndexBuilder;
use SQLCraft\DDL\DropIndexBuilder;

// Create a standalone index (outside CREATE TABLE)
$createIdx = new CreateIndexBuilder(
    table: new QualifiedName(new Identifier('orders')),
    index: $emailIdx,
    ifNotExists: true,
);
$db->ddl()->execute($db->connection(), $createIdx);

// Drop an index
$dropIdx = new DropIndexBuilder(
    table: new QualifiedName(new Identifier('orders')),
    indexName: new Identifier('uq_users_email'),
    ifExists: true,
);
$db->ddl()->execute($db->connection(), $dropIdx);
```

---

## `CreateViewBuilder` and `DropViewBuilder`

```php
use SQLCraft\DDL\CreateViewBuilder;
use SQLCraft\DDL\DropViewBuilder;
use SQLCraft\ValueObjects\Identifier;

$createView = new CreateViewBuilder(
    name: new QualifiedName(new Identifier('active_users')),
    selectSql: 'SELECT id, email FROM users WHERE deleted_at IS NULL',
    orReplace: true,
    columns: [],
    checkOption: null, // 'CASCADED' | 'LOCAL' | null
);
$db->ddl()->execute($db->connection(), $createView);

$dropView = new DropViewBuilder(
    name: new QualifiedName(new Identifier('active_users')),
    ifExists: true,
    cascade: false,
);
$db->ddl()->execute($db->connection(), $dropView);
```

---

## `CreateTriggerBuilder` and `DropTriggerBuilder`

Triggers require the `Trigger` capability. On platforms that do not support triggers, `execute()` throws `CapabilityNotSupportedException`.

```php
use SQLCraft\DDL\CreateTriggerBuilder;
use SQLCraft\DDL\DropTriggerBuilder;
use SQLCraft\ValueObjects\TriggerTiming;
use SQLCraft\ValueObjects\TriggerEvent;
use SQLCraft\Capabilities\Capability;

$caps = $db->connection()->getPlatform()->getCapabilities();
$caps->require(Capability::Trigger); // throws if not supported

$createTrigger = new CreateTriggerBuilder(
    name: new QualifiedName(new Identifier('trg_orders_updated_at')),
    table: new QualifiedName(new Identifier('orders')),
    timing: TriggerTiming::BEFORE,
    event: TriggerEvent::UPDATE,
    body: 'SET NEW.updated_at = NOW()',
    definer: null,
    forEach: 'ROW',
);
$db->ddl()->execute($db->connection(), $createTrigger);

$dropTrigger = new DropTriggerBuilder(
    name: new QualifiedName(new Identifier('trg_orders_updated_at')),
    table: new QualifiedName(new Identifier('orders')), // required on MySQL
    ifExists: true,
);
$db->ddl()->execute($db->connection(), $dropTrigger);
```

---

## `CreateSequenceBuilder` — Capability-Gated

Sequences are only supported on PostgreSQL and SQL Server. Calling this on MySQL throws `CapabilityNotSupportedException`.

```php
use SQLCraft\DDL\CreateSequenceBuilder;
use SQLCraft\Capabilities\Capability;
use SQLCraft\Capabilities\CapabilityNotSupportedException;

$caps = $db->connection()->getPlatform()->getCapabilities();

try {
    $caps->require(Capability::Sequence);

    $seq = new CreateSequenceBuilder(
        name: new Identifier('order_id_seq'),
        start: 1000,
        increment: 1,
        min: 1,
        max: null,
        cycle: false,
        cache: 10,
    );
    $db->ddl()->execute($db->connection(), $seq);

} catch (CapabilityNotSupportedException $e) {
    // MySQL / SQLite: sequences not supported
}
```

---

## Previewing SQL Before Execution

Every builder supports `toSql()` directly for ad-hoc inspection, and `DdlManager::preview()` for the same via the manager:

```php
// Via the builder directly
$platform = $db->connection()->getPlatform();
$statements = $builder->toSql($platform); // list<string>
foreach ($statements as $stmt) {
    echo $stmt . PHP_EOL;
}

// Via DdlManager (preferred — uses the live connection's platform)
$statements = $db->ddl()->preview($db->connection(), $builder);
```

This is useful for logging migration plans, generating review diffs, or auditing before a destructive operation.

---

## Platform-Specific Examples

### MySQL: Full Table Creation

```php
$table = new CreateTableBuilder(
    table: new QualifiedName(new Identifier('products')),
    engine: 'InnoDB',
    charset: 'utf8mb4',
    collation: 'utf8mb4_unicode_ci',
    ifNotExists: true,
);

$table = $table
    ->withColumn(new ColumnDefinition('id', new DataType('bigint', unsigned: true), false, true, true, false, DefaultValue::none(), null, null, null, [], null, null))
    ->withColumn(new ColumnDefinition('sku', new DataType('varchar', length: 64), false, false, false, false, DefaultValue::none(), null, null, null, [], null, null))
    ->withColumn(new ColumnDefinition('price', new DataType('decimal', precision: 10, scale: 2), false, false, false, false, DefaultValue::literal('0.00'), null, null, null, [], null, null))
    ->withIndex(new IndexDefinition('PRIMARY', IndexType::PRIMARY, [new IndexColumnDefinition('id')], true, null, 'BTREE', null))
    ->withIndex(new IndexDefinition('uq_sku', IndexType::UNIQUE, [new IndexColumnDefinition('sku')], true, null, null, null));

$db->ddl()->execute($db->connection(), $table);
```

### PostgreSQL: Schema-Qualified Table with Deferrable FK

```php
$table = new QualifiedName(new Identifier('line_items'), new Identifier('public'));
$builder = new CreateTableBuilder(table: $table);

$fk = new ForeignKeyDefinition('fk_line_items_order', null, 'public', 'orders', ['order_id'], ['id'], ForeignKeyAction::Cascade, ForeignKeyAction::Restrict, null, deferrable: true);

$builder = $builder->withColumn(/* ... */)->withForeignKey($fk);
$db->ddl()->execute($db->connection(), $builder);
```

### SQLite: Alter Table (Recreation Handled Automatically)

```php
$alter = (new AlterTableBuilder(new QualifiedName(new Identifier('notes'))))
    ->dropColumn(new Identifier('legacy_flag'))
    ->withColumn(new ColumnDefinition('pinned', new DataType('integer'), false, false, false, false, DefaultValue::literal('0'), null, null, null, [], null, null));

// DdlManager detects SQLite and uses TableRecreationStrategy automatically
$db->ddl()->execute($db->connection(), $alter);
```

### SQL Server: Named Default Constraint

```php
$col = new ColumnDefinition(
    name: 'created_at',
    dataType: new DataType('datetime2'),
    nullable: false,
    autoIncrement: false,
    primary: false,
    generated: false,
    default: DefaultValue::expression('GETUTCDATE()'),
    collation: null,
    comment: null,
    onUpdate: null,
    privileges: [],
    originalName: null,
    defaultConstraintName: 'DF_orders_created_at',
);
```

---

## Best Practices

- Always call `preview()` before executing destructive operations (`DROP TABLE`, `TRUNCATE`) in non-development environments.
- Use `ifNotExists: true` on `CreateTableBuilder` and `DropTableBuilder` to make migration scripts idempotent.
- Use `ifExists: true` on `DropIndexBuilder`, `DropViewBuilder`, and `DropTriggerBuilder` for the same reason.
- Check `Capability::Trigger` and `Capability::Sequence` before constructing their builders in code that runs across multiple platforms.
- On MySQL, always provide `engine` and `charset` in `CreateTableBuilder` to avoid inheriting server defaults that may differ between environments.
- Build immutable builder values and store them — their `toSql()` output is deterministic and safe to log or test.
