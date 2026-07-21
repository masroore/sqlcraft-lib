# 13 — DDL Services

> **Status:** Design draft
> **Scope:** `SQLCraft\DDL` namespace — DDL generation architecture, `DdlBuilder` family, `DdlDialectInterface` (extended from 08-driver-architecture.md), SQLite table recreation, schema diff, auto-increment/sequence handling, identifier keyword collision, DDL execution
> **Depends on:** 05-domain-model.md (ColumnMeta, IndexMeta, ForeignKeyMeta, VOs), 08-driver-architecture.md (DdlDialectInterface, PlatformInterface), 09-capability-model.md (Capability enum), 10-connection-layer.md (ConnectionInterface), 12-query-engine.md (QueryExecutor)
> **Namespace root:** `SQLCraft\DDL`

---

## 1. Architecture Philosophy

### 1.1 Three Options Considered

Three architectural patterns exist for DDL generation in a multi-engine library:

**Option A — Template-based string assembly (Adminer's approach)**

Adminer generates DDL by composing SQL directly from metadata using free functions (`create_sql()`, `alter_sql()`, `index_sql()`, etc.) that concatenate strings with engine-specific branches. Each function is a mix of SQL fragments and conditional logic:

```php
// Adminer pattern — do NOT copy
function create_sql(string $table, bool $auto_increment, string $style): string {
    $fields  = fields($table);
    $indexes = indexes($table);
    $sql     = "CREATE TABLE " . idf_escape($table) . " (\n";
    // ... imperative string concatenation with engine branches
}
```

Problems: functions mutate shared state via globals; engine branches live inside the same function; no separation between "what to create" and "how to render it"; impossible to unit-test without a live connection; difficult to preview DDL before execution.

**Option B — Full AST (Abstract Syntax Tree)**

A pure AST approach builds a tree of nodes (`CreateTableNode → ColumnDefinitionNode → DataTypeNode → …`) and walks it with a visitor per engine. This is the approach of DBAL's AbstractPlatform and Doctrine's Schema classes.

Pros: maximally composable; every node is testable; supports arbitrary SQL transformations. Cons: very high complexity — a complete SQL DDL AST is large (dozens of node types); the visitor pattern for 6 engines × every DDL operation is hundreds of classes; most SQLCraft consumers need only straightforward DDL operations, not AST transformation pipelines. Overkill for the use case.

**Option C — Immutable builder VOs with platform-delegated rendering (Recommended)**

An intermediate path: each DDL operation type gets an immutable value object (builder) that records the *intent* of the DDL change using typed properties from the domain model (05-domain-model.md). Rendering is delegated entirely to the platform's `DdlDialectInterface`. The builder holds no SQL; the platform produces SQL.

```
CreateTableBuilder (immutable VO)
    → platform->renderCreateTable(builder) → SQL string
```

This implementation uses DDL-facing metadata projection contracts (`Contracts\DDL\*DefinitionInterface`) rather than importing `DTO` classes into `SQLCraft\DDL`. The dependency graph deliberately keeps DDL independent from DTO; immutable projection implementations live under `SQLCraft\DDL\Definition`, and `AbstractPlatform` adapts projections to the existing platform DTO renderers. This preserves the typed metadata semantics without weakening the architectural boundary.

This gives:
- **Separation of intent from rendering.** The builder describes *what* you want; the platform decides *how* to express it in that engine's dialect.
- **Testability without a live DB.** Unit tests can assert on builder state and mock the platform renderer.
- **Preview before execution.** `$builder->toSql($platform)` returns a SQL string the consumer can inspect. `$builder->execute($conn)` runs it.
- **Manageable complexity.** Builder VOs are thin (just typed properties + wither methods). The complex dialect logic lives in the platform, which already owns quoting, typing, and introspection SQL.
- **Consistency with domain model.** Builders consume the same `ColumnMeta`, `IndexMeta`, `ForeignKeyMeta` VOs used everywhere else.

**Decision: Option C.** The AST approach is reserved for a hypothetical future `SQLCraft\Sql\Ast` module if query transformation becomes a core need. The DDL system uses immutable builders with platform-delegated rendering.

### 1.2 Tradeoffs Acknowledged

| Concern | Implication |
|---------|-------------|
| SQL completeness | Builders cover the common 95% of DDL operations. Exotic engine-specific DDL (e.g., MySQL partitioning subpartition templates) is exposed via a `rawDdl(string $sql)` escape hatch on `DdlDialectInterface`. |
| Cross-engine correctness | The builder only holds engine-neutral intent. The platform renderer is responsible for mapping intent to valid engine SQL; if a feature is unsupported (e.g., named CHECK constraints on MySQL 5.7), `CapabilityNotSupportedException` is thrown at render time. |
| Complexity budget | Six engines × 15+ DDL operation types = ~90 render methods across 6 platform classes. This is manageable because `AbstractPlatform` provides SQL-standard defaults and each concrete class overrides only what diverges. |
| ALTER TABLE complexity | ALTER TABLE is the hardest operation (especially SQLite's full table recreation). This is treated as a first-class design challenge in §5. |

---

## 2. The `DdlBuilder` Family

Each DDL operation type has a dedicated immutable builder VO. Builders are constructed with a fluent `with*()` API (PHP 8.4 `clone with` semantics via explicit wither methods). No builder holds a connection; rendering is always triggered explicitly.

### 2.1 Common Interface

```php
namespace SQLCraft\Contracts\DDL;

use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\Contracts\Connection\ConnectionInterface;

interface DdlBuilderInterface
{
    /**
     * Render this DDL operation to a SQL string (or array of statements).
     * May return multiple statements for operations that require them (e.g., PgSQL multi-ALTER).
     *
     * @return list<string>
     */
    public function toSql(DdlDialectInterface $dialect): array;

    /**
     * Execute this DDL operation against the given connection.
     * Uses QueryExecutor::executeDdl() (12-query-engine.md §2) internally.
     * Fires BeforeDdlExecuted / AfterDdlExecuted events (16-events.md §5.2).
     */
    public function execute(ConnectionInterface $conn): void;
}
```

`toSql()` returns a `list<string>` (not a single string) because some DDL operations require multiple statements on some engines (PgSQL ALTER TABLE issuing one statement per change; SQLite table recreation requiring 4+ statements). The caller may join them for display but must execute them separately via `execute()`.

### 2.2 `CreateTableBuilder`

```php
namespace SQLCraft\DDL;

use SQLCraft\ValueObjects\QualifiedName;
use SQLCraft\DTO\{ColumnMeta, IndexMeta, ForeignKeyMeta};

final readonly class CreateTableBuilder implements DdlBuilderInterface
{
    /** @param list<ColumnMeta>       $columns */
    /** @param list<IndexMeta>        $indexes */
    /** @param list<ForeignKeyMeta>   $foreignKeys */
    /** @param list<CheckConstraintMeta> $checkConstraints */
    public function __construct(
        public readonly QualifiedName $table,
        public readonly array         $columns           = [],
        public readonly array         $indexes           = [],
        public readonly array         $foreignKeys       = [],
        public readonly array         $checkConstraints  = [],
        public readonly ?string       $engine            = null,   // MySQL: 'InnoDB'
        public readonly ?string       $charset           = null,
        public readonly ?string       $collation         = null,
        public readonly ?string       $comment           = null,
        public readonly bool          $ifNotExists       = false,
        public readonly bool          $temporary         = false,
        public readonly bool          $includeAutoIncrementValue = false,
        public readonly ?int          $autoIncrementValue = null,
    ) {}

    public function withColumn(ColumnMeta $column): self
    {
        return new self(..., columns: [...$this->columns, $column]);
    }

    public function withIndex(IndexMeta $index): self
    {
        return new self(..., indexes: [...$this->indexes, $index]);
    }

    public function withForeignKey(ForeignKeyMeta $fk): self
    {
        return new self(..., foreignKeys: [...$this->foreignKeys, $fk]);
    }

    public function withoutAutoIncrementValue(): self
    {
        return new self(..., includeAutoIncrementValue: false, autoIncrementValue: null);
    }

    public function toSql(DdlDialectInterface $dialect): array
    {
        return [$dialect->renderCreateTable($this)];
    }

    public function execute(ConnectionInterface $conn): void
    {
        foreach ($this->toSql($conn->getPlatform()) as $sql) {
            $conn->getPlatform()->getQueryExecutor()->executeDdl($conn, $sql);
        }
    }
}
```

**Note on `execute()` wiring:** The `execute()` method routes through `QueryExecutor::executeDdl()` (12-query-engine.md §2), which fires `BeforeDdlExecuted` / `AfterDdlExecuted` events, invalidates the metadata cache, and handles the MySQL auto-commit behavior for DDL. Builders never call `ConnectionInterface::execute()` directly.

### 2.3 Other Builder Types

| Builder | Key properties | Notes |
|---------|---------------|-------|
| `AlterTableBuilder` | `$table`, `$addColumns`, `$modifyColumns`, `$dropColumns`, `$addIndexes`, `$dropIndexes`, `$addForeignKeys`, `$dropForeignKeys`, `$addCheckConstraints`, `$dropCheckConstraints`, `$rename` | Most complex builder; see §5 for per-engine rendering |
| `DropTableBuilder` | `$table`, `$ifExists`, `$cascade` | `$cascade` only for PgSQL/Oracle; ignored on MySQL/SQLite |
| `CreateIndexBuilder` | `$table`, `IndexMeta $index`, `$ifNotExists` | Covers UNIQUE, FULLTEXT, SPATIAL, PARTIAL (PgSQL/SQLite) |
| `DropIndexBuilder` | `$table`, `$indexName`, `$ifExists` | MySQL: `DROP INDEX name ON table`; PgSQL: `DROP INDEX name` |
| `TruncateBuilder` | `$table`, `$cascade`, `$restartIdentity` | Engine mapping in §3 |
| `CreateViewBuilder` | `$name`, `$selectSql`, `$orReplace`, `$columns`, `$checkOption` | `$selectSql` is a raw SQL string (not a SelectQuery VO) — views can have arbitrary SELECT |
| `DropViewBuilder` | `$name`, `$ifExists`, `$cascade` | |
| `CreateTriggerBuilder` | `$name`, `$table`, `TriggerTiming`, `TriggerEvent`, `$body`, `$definer`, `$forEach` | Platform renders DELIMITER wrapping where needed |
| `DropTriggerBuilder` | `$name`, `$table`, `$ifExists` | SQLite requires table name; others do not |
| `CreateRoutineBuilder` | `$name`, `$type` (FUNCTION/PROCEDURE), `$params`, `$returnType`, `$body`, `$language`, `$deterministic`, `$orReplace` | Platform adds DELIMITER wrapping (MySQL) or `$$` quoting (PgSQL) |
| `DropRoutineBuilder` | `$name`, `$type`, `$ifExists` | |
| `CreateSequenceBuilder` | `$name`, `$start`, `$increment`, `$min`, `$max`, `$cycle`, `$cache` | Capability-gated (Capability::Sequence); MySQL has no native sequence |
| `DropSequenceBuilder` | `$name`, `$ifExists` | |
| `CreateDatabaseBuilder` | `$name`, `$charset`, `$collation`, `$ifNotExists` | |
| `DropDatabaseBuilder` | `$name`, `$ifExists` | |
| `CreateSchemaBuilder` | `$name`, `$authorization`, `$ifNotExists` | Capability-gated (Capability::Scheme) |
| `DropSchemaBuilder` | `$name`, `$ifExists`, `$cascade` | |
| `UseDatabaseBuilder` | `$database` | Renders USE/\c/ATTACH depending on platform |

All builders follow the same `toSql(DdlDialectInterface): list<string>` + `execute(ConnectionInterface): void` contract. The platform receives the builder as a typed parameter; it reads only the properties it needs for its dialect.

---

## 3. `DdlDialectInterface` — Expanded

The interface sketch in 08-driver-architecture.md §11 is expanded here with the full method list. Every `render*` method receives a typed builder VO and returns a SQL string (or throws if the operation is not supported by this platform).

```php
namespace SQLCraft\Contracts\Platform;

interface DdlDialectInterface
{
    // --- Column definitions ---
    public function renderColumnDefinition(ColumnMeta $column): string;
    public function renderAddColumn(QualifiedName $table, ColumnMeta $column, ?string $afterColumn = null): string;
    public function renderModifyColumn(QualifiedName $table, ColumnMeta $new, ColumnMeta $original): string;
    public function renderDropColumn(QualifiedName $table, Identifier $column): string;

    // --- Table DDL ---
    public function renderCreateTable(CreateTableBuilder $builder): string;
    public function renderDropTable(DropTableBuilder $builder): string;
    public function renderTruncate(TruncateBuilder $builder): string;
    public function renderUseDatabase(UseDatabaseBuilder $builder): string;

    // --- ALTER TABLE ---
    /** Returns list<string> — may be multiple statements (PgSQL, SQLite, MSSQL). */
    public function renderAlterTable(AlterTableBuilder $builder): array;

    // --- Index DDL ---
    public function renderCreateIndex(CreateIndexBuilder $builder): string;
    public function renderDropIndex(DropIndexBuilder $builder): string;

    // --- Constraint DDL ---
    public function renderAddForeignKey(QualifiedName $table, ForeignKeyMeta $fk): string;
    public function renderDropForeignKey(QualifiedName $table, Identifier $constraintName): string;
    public function renderAddCheckConstraint(QualifiedName $table, CheckConstraintMeta $check): string;
    public function renderDropCheckConstraint(QualifiedName $table, Identifier $constraintName): string;
    public function renderPrimaryKeyClause(IndexMeta $index): string;

    // --- View DDL ---
    public function renderCreateView(CreateViewBuilder $builder): string;
    public function renderDropView(DropViewBuilder $builder): string;

    // --- Trigger DDL ---
    public function renderCreateTrigger(CreateTriggerBuilder $builder): string;
    public function renderDropTrigger(DropTriggerBuilder $builder): string;

    // --- Routine DDL ---
    public function renderCreateRoutine(CreateRoutineBuilder $builder): string;
    public function renderDropRoutine(DropRoutineBuilder $builder): string;

    // --- Sequence DDL (Capability::Sequence required) ---
    public function renderCreateSequence(CreateSequenceBuilder $builder): string;
    public function renderDropSequence(DropSequenceBuilder $builder): string;

    // --- Database / Schema DDL ---
    public function renderCreateDatabase(CreateDatabaseBuilder $builder): string;
    public function renderDropDatabase(DropDatabaseBuilder $builder): string;
    public function renderCreateSchema(CreateSchemaBuilder $builder): string;
    public function renderDropSchema(DropSchemaBuilder $builder): string;
}
```

`AbstractPlatform` provides SQL-standard defaults for the subset of methods where a standard form exists (e.g., `renderDropTable` → `DROP TABLE IF EXISTS {name}`). Concrete platforms override only what diverges.

Methods that touch a feature absent from the platform (e.g., `renderCreateSequence` on `MySQLPlatform`) must throw `CapabilityNotSupportedException::for(Capability::Sequence, 'mysql')` rather than returning an empty string or silently producing invalid SQL.

### 3.1 Per-Engine `renderAlterTable` Examples

`renderAlterTable` is the most engine-divergent method. It receives an `AlterTableBuilder` and returns `list<string>`.

**MySQL — single multi-clause ALTER TABLE:**

```php
// MySQLPlatform::renderAlterTable()
// MySQL allows batching all changes into one ALTER TABLE statement.
// This is preferable: fewer round trips, atomic on InnoDB for metadata changes.
public function renderAlterTable(AlterTableBuilder $builder): array
{
    $table   = $this->quoteIdentifier($builder->table->object);
    $clauses = [];

    foreach ($builder->dropForeignKeys as $fkName) {
        $clauses[] = "DROP FOREIGN KEY {$this->quoteIdentifier($fkName)}";
    }
    foreach ($builder->dropIndexes as $idx) {
        $clauses[] = "DROP INDEX {$this->quoteIdentifier($idx)}";
    }
    foreach ($builder->dropColumns as $col) {
        $clauses[] = "DROP COLUMN {$this->quoteIdentifier($col)}";
    }
    foreach ($builder->modifyColumns as [$new, $original]) {
        $clauses[] = "CHANGE COLUMN {$this->quoteIdentifier($original->name)} "
                   . $this->renderColumnDefinition($new);
    }
    foreach ($builder->addColumns as [$col, $after]) {
        $pos       = $after ? " AFTER {$this->quoteIdentifier($after)}" : '';
        $clauses[] = "ADD COLUMN {$this->renderColumnDefinition($col)}{$pos}";
    }
    foreach ($builder->addIndexes as $index) {
        $clauses[] = "ADD " . $this->renderIndexClause($index);
    }
    foreach ($builder->addForeignKeys as $fk) {
        $clauses[] = "ADD " . $this->renderForeignKeyClause($fk);
    }
    if ($builder->rename !== null) {
        $clauses[] = "RENAME TO {$this->quoteIdentifier($builder->rename)}";
    }

    if (empty($clauses)) {
        return [];
    }
    return ["ALTER TABLE {$table}\n  " . implode(",\n  ", $clauses)];
}
```

**PostgreSQL — one statement per change:**

PostgreSQL supports transactional DDL, so multiple `ALTER TABLE` statements in a transaction are safe and idiomatic. Each change is a separate statement:

```php
// PostgreSQLPlatform::renderAlterTable()
public function renderAlterTable(AlterTableBuilder $builder): array
{
    $table = $this->quoteQualifiedName($builder->table);
    $stmts = [];

    foreach ($builder->dropForeignKeys as $fkName) {
        $stmts[] = "ALTER TABLE {$table} DROP CONSTRAINT {$this->quoteIdentifier($fkName)}";
    }
    foreach ($builder->dropColumns as $col) {
        $stmts[] = "ALTER TABLE {$table} DROP COLUMN {$this->quoteIdentifier($col)}";
    }
    foreach ($builder->modifyColumns as [$new, $original]) {
        // PgSQL uses multiple ALTER COLUMN sub-commands per column property change
        $stmts = array_merge($stmts, $this->renderModifyColumnStatements($table, $new, $original));
    }
    foreach ($builder->addColumns as [$col]) {
        $stmts[] = "ALTER TABLE {$table} ADD COLUMN {$this->renderColumnDefinition($col)}";
    }
    foreach ($builder->addForeignKeys as $fk) {
        $stmts[] = "ALTER TABLE {$table} ADD {$this->renderForeignKeyClause($fk)}";
    }
    foreach ($builder->addCheckConstraints as $check) {
        $stmts[] = "ALTER TABLE {$table} ADD CONSTRAINT {$this->quoteIdentifier($check->name)} CHECK ({$check->expression})";
    }
    if ($builder->rename !== null) {
        $stmts[] = "ALTER TABLE {$table} RENAME TO {$this->quoteIdentifier($builder->rename)}";
    }
    return $stmts;
}
```

**MSSQL — multiple ALTER TABLE, with special syntax for default constraints:**

MSSQL requires named DEFAULT constraints to be dropped explicitly before modifying a column. The platform tracks this:

```php
// SqlServerPlatform::renderAlterTable() — abridged
// Dropping a column with a default constraint requires first dropping the named constraint.
foreach ($builder->dropColumns as $col) {
    if ($col->defaultConstraintName !== null) {
        $stmts[] = "ALTER TABLE {$table} DROP CONSTRAINT [{$col->defaultConstraintName}]";
    }
    $stmts[] = "ALTER TABLE {$table} DROP COLUMN [{$col->name}]";
}
```

**SQLite — full table recreation (see §5 for full pattern):**

```php
// SqlitePlatform::renderAlterTable() — delegates to TableRecreationStrategy
public function renderAlterTable(AlterTableBuilder $builder): array
{
    $needs = $this->needsRecreation($builder);
    if ($needs) {
        return $this->recreationStrategy->renderRecreation($builder);
    }
    // SQLite supports ADD COLUMN without recreation (if no other changes)
    $stmts = [];
    foreach ($builder->addColumns as [$col]) {
        $stmts[] = "ALTER TABLE {$this->quoteIdentifier($builder->table->object)} ADD COLUMN {$this->renderColumnDefinition($col)}";
    }
    if ($builder->rename !== null) {
        $stmts[] = "ALTER TABLE {$this->quoteIdentifier($builder->table->object)} RENAME TO {$this->quoteIdentifier($builder->rename)}";
    }
    return $stmts;
}

private function needsRecreation(AlterTableBuilder $b): bool
{
    // SQLite requires recreation for: any column modification, any column drop,
    // any FK change, any check constraint change, any index change on existing columns.
    return !empty($b->modifyColumns)
        || !empty($b->dropColumns)
        || !empty($b->addForeignKeys)
        || !empty($b->dropForeignKeys)
        || !empty($b->addCheckConstraints)
        || !empty($b->dropCheckConstraints)
        || !empty($b->dropIndexes);
}
```

### 3.2 `TruncateBuilder` Engine Mapping

| Engine | Rendered SQL |
|--------|-------------|
| MySQL / MariaDB | `TRUNCATE TABLE {table}` |
| PostgreSQL | `TRUNCATE TABLE {table}` (`RESTART IDENTITY` / `CASCADE` appended per builder flags) |
| MSSQL | `TRUNCATE TABLE {table}` |
| SQLite | `DELETE FROM {table}` (SQLite has no TRUNCATE; `sqlite_sequence` reset handled separately if `$restartIdentity`) |
| Oracle | `TRUNCATE TABLE {table}` (`REUSE STORAGE` / `DROP STORAGE` per engine defaults, not exposed as a builder option in v1) |

This mirrors Adminer's `truncate_sql()` per-driver behavior (MySQL/PgSQL/MSSQL use TRUNCATE; SQLite/Oracle-in-some-modes use DELETE), now encapsulated in one method per platform instead of a free function with an implicit engine switch.

---

## 4. Identifier Generation and Cross-Engine Keyword Collisions

An identifier valid in one engine may be a reserved keyword in another (e.g., `user` is unreserved in MySQL but reserved in PostgreSQL; `order` is reserved almost everywhere). SQLCraft's stance:

1. **Quoting is always applied.** Every rendered identifier goes through `QuotingInterface::quoteIdentifier()` (08-driver-architecture.md §3.1) — SQLCraft never emits an unquoted identifier in generated DDL, regardless of whether quoting is strictly necessary. This eliminates keyword-collision bugs entirely rather than attempting to detect them.
2. **`PlatformInterface::getKeywordList()`** (08-driver-architecture.md §3.4) is exposed for consumers who want to *warn* users authoring cross-engine-portable schemas ("column name `order` is a reserved word in PostgreSQL; it will still work because SQLCraft quotes it, but avoid it if you want plain SQL portability outside SQLCraft"). This is advisory only — quoting means the DDL itself always works.
3. **No automatic renaming.** SQLCraft never silently renames an identifier to avoid a collision. Given SQLCraft is not a migration/portability tool, changing an object's name without the caller's explicit intent would be a correctness hazard (renames break references).
4. **Case sensitivity remains a platform concern.** As established in 05-domain-model.md §10, `Identifier` equality is case-sensitive; `PlatformInterface::normalizeIdentifier()` handles engine-specific folding (MySQL/SQLite fold unquoted identifiers to lowercase on some platforms/filesystems; PostgreSQL folds unquoted to lowercase always; quoted identifiers are always case-preserved). DDL builders always quote, so case is always preserved exactly as given in the `Identifier` VO.

---

## 5. SQLite Table Recreation Strategy

### 5.1 The Problem

SQLite's `ALTER TABLE` support is deliberately minimal: `ADD COLUMN` (with restrictions — no non-constant default without a later SQLite version, no `NOT NULL` without a default) and `RENAME TABLE`/`RENAME COLUMN` are supported natively. Everything else — dropping a column, modifying a column's type/constraints, adding or dropping a foreign key, adding or dropping a CHECK constraint, changing PRIMARY KEY — requires **full table reconstruction**: create a new table with the desired final schema, copy the data across, drop the old table, and rename the new one into place. Adminer implements this via `recreate_table(table, after, columns, indexes, foreign_keys)`.

### 5.2 `TableRecreationStrategy`

SQLCraft formalizes this as a dedicated, transactional strategy rather than inline procedural code:

```php
namespace SQLCraft\DDL\Sqlite;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Execution\TransactionManagerInterface;
use SQLCraft\DDL\AlterTableBuilder;

/** @internal — used only by SqlitePlatform::renderAlterTable() dispatch; not part of public API */
final class TableRecreationStrategy
{
    public function __construct(
        private readonly TransactionManagerInterface $transactions,
    ) {}

    /**
     * Execute a full table recreation for the given ALTER TABLE intent.
     * Wrapped in a transaction: if any step fails, the original table is untouched.
     */
    public function execute(ConnectionInterface $conn, AlterTableBuilder $builder): void
    {
        $this->transactions->transactional($conn, function (ConnectionInterface $conn) use ($builder) {
            $original   = $builder->table;
            $tempName   = $this->tempTableName($original);
            $finalMeta  = $this->computeFinalSchema($conn, $builder); // merges existing schema + builder intent

            // 1. Disable foreign key enforcement for this connection during recreation
            //    (SQLite FKs referencing this table are otherwise violated mid-recreation).
            $conn->execute('PRAGMA foreign_keys = OFF');

            // 2. Create the new table under a temporary name with the FINAL desired schema.
            $createBuilder = CreateTableBuilder::fromTableMeta($finalMeta)->withTable($tempName);
            $createBuilder->execute($conn);

            // 3. Copy data across using only the columns that survive (dropped columns excluded,
            //    renamed columns mapped old-name -> new-name).
            $columnMap = $this->buildColumnMap($builder); // ['old_col' => 'new_col', ...]
            $insertSql = $this->renderCopySql($original, $tempName, $columnMap);
            $conn->execute($insertSql);

            // 4. Drop the original table.
            (new DropTableBuilder($original))->execute($conn);

            // 5. Rename the temp table into the original's place.
            $conn->execute("ALTER TABLE {$this->quote($tempName)} RENAME TO {$this->quote($original->object)}");

            // 6. Recreate indexes and triggers that referenced the original table
            //    (SQLite does not carry these over automatically).
            foreach ($finalMeta->indexes as $index) {
                (new CreateIndexBuilder($original, $index))->execute($conn);
            }
            foreach ($finalMeta->triggers as $trigger) {
                (new CreateTriggerBuilder(...))->execute($conn);
            }

            // 7. Re-enable foreign key enforcement and verify integrity.
            $conn->execute('PRAGMA foreign_keys = ON');
            $violations = $conn->query('PRAGMA foreign_key_check')->fetchAll();
            if (!empty($violations)) {
                throw new ForeignKeyConstraintException(
                    'Table recreation produced foreign key violations: ' . json_encode($violations),
                );
            }
        });
    }

    private function tempTableName(QualifiedName $original): Identifier
    {
        return new Identifier('_sqlcraft_recreate_' . $original->object->name . '_' . bin2hex(random_bytes(4)));
    }
}
```

### 5.3 Why This Must Be Transactional

Adminer's `recreate_table()` performs the same sequence of steps but relies on the fact that a web request typically runs the whole sequence in one PHP process with no interruption; if it fails partway, the database may be left with both a temp table and a dropped original, or a renamed table missing indexes. SQLCraft treats this as unacceptable for a library other applications depend on programmatically — a partial recreation silently corrupts the schema.

`TableRecreationStrategy::execute()` wraps the entire sequence in `TransactionManagerInterface::transactional()` (12-query-engine.md §5.3). SQLite supports transactional DDL (unlike MySQL), so this is safe: if step 3, 5, or 7 throws, the transaction rolls back and the original table is untouched. The foreign-key integrity check in step 7 is a deliberate safety net — SQLite does not enforce FK constraints transactionally by default (`PRAGMA foreign_keys` is a connection-level pragma, not transactional), so an explicit post-recreation `PRAGMA foreign_key_check` catches violations that would otherwise pass silently.

### 5.4 Which Operations Trigger Recreation

| Operation | SQLite native support | Requires recreation |
|-----------|----------------------|---------------------|
| ADD COLUMN (nullable, or with constant default) | Yes | No |
| ADD COLUMN (NOT NULL, no default) | No | Yes |
| DROP COLUMN | No (SQLite ≥3.35.0 added limited native `DROP COLUMN`; SQLCraft still recreates for restricted cases — see note) | Conditionally |
| MODIFY COLUMN (type/nullability/default change) | No | Yes |
| RENAME COLUMN | Yes (SQLite ≥3.25.0) | No |
| RENAME TABLE | Yes | No |
| ADD FOREIGN KEY | No | Yes |
| DROP FOREIGN KEY | No | Yes |
| ADD CHECK CONSTRAINT | No | Yes |
| DROP CHECK CONSTRAINT | No | Yes |
| ADD/DROP INDEX | Yes (independent `CREATE INDEX`/`DROP INDEX`) | No |
| Change PRIMARY KEY | No | Yes |

**Note on native `DROP COLUMN` (SQLite ≥3.35.0):** SQLite added a native `ALTER TABLE ... DROP COLUMN`, but it refuses if the column is part of a PRIMARY KEY, has a UNIQUE or CHECK constraint referencing it, or is referenced by a generated column or indexed expression. `SqlitePlatform::needsRecreation()` inspects the target column against these restrictions via `IntrospectionDialectInterface` before deciding whether the native path or recreation is required, and uses the native path when safe (fewer statements, faster, no data copy).

---

## 6. Schema Diff and Comparison

### 6.1 Scope Decision

Full schema-diff-driven migration generation (compare two `TableMeta` snapshots or two live databases and emit the DDL to transform one into the other) is a substantial feature with its own edge cases (column reordering semantics, rename detection heuristics, safe-vs-unsafe diff classification). It is **planned for v1.1+**, not the initial release. The v1 scope is limited to: DDL builders (§2) that a caller constructs explicitly, plus the diff *data structures* below so that early consumers and the v1.1 implementation share a stable shape. The diff *renderer* (turning a diff into DDL) ships in v1.1.

### 6.2 `SchemaDiff`

```php
namespace SQLCraft\DDL\Diff;

final readonly class SchemaDiff
{
    /**
     * @param list<QualifiedName>   $tablesAdded
     * @param list<QualifiedName>   $tablesRemoved
     * @param list<TableDiff>       $tablesAltered
     */
    public function __construct(
        public readonly array $tablesAdded   = [],
        public readonly array $tablesRemoved = [],
        public readonly array $tablesAltered = [],
    ) {}

    public function isEmpty(): bool
    {
        return empty($this->tablesAdded) && empty($this->tablesRemoved) && empty($this->tablesAltered);
    }
}
```

### 6.3 `TableDiff` and `ColumnDiff`

```php
final readonly class TableDiff
{
    /**
     * @param list<ColumnMeta>      $columnsAdded
     * @param list<Identifier>      $columnsRemoved
     * @param list<ColumnDiff>      $columnsModified
     * @param list<IndexMeta>       $indexesAdded
     * @param list<Identifier>      $indexesRemoved
     * @param list<ForeignKeyMeta>  $foreignKeysAdded
     * @param list<Identifier>      $foreignKeysRemoved
     */
    public function __construct(
        public readonly QualifiedName $table,
        public readonly array         $columnsAdded       = [],
        public readonly array         $columnsRemoved     = [],
        public readonly array         $columnsModified    = [],
        public readonly array         $indexesAdded       = [],
        public readonly array         $indexesRemoved     = [],
        public readonly array         $foreignKeysAdded   = [],
        public readonly array         $foreignKeysRemoved = [],
    ) {}
}

/**
 * Distinguishes WHAT changed about a column — a rename, a type change,
 * and a constraint change are semantically different operations even
 * though they all "modify" a column.
 */
final readonly class ColumnDiff
{
    public function __construct(
        public readonly ColumnMeta $before,
        public readonly ColumnMeta $after,
        public readonly bool       $nameChanged,
        public readonly bool       $typeChanged,
        public readonly bool       $nullabilityChanged,
        public readonly bool       $defaultChanged,
        public readonly bool       $collationChanged,
        // Structural ordering (column position) is tracked separately because
        // most engines other than MySQL do not support column reordering at all —
        // a position-only diff should not trigger a destructive recreate on those engines.
        public readonly bool       $positionChanged,
    ) {}

    /** True if the change requires SQLite table recreation (§5) when targeting SQLite. */
    public function requiresSqliteRecreation(): bool
    {
        return $this->typeChanged || $this->nullabilityChanged || $this->defaultChanged;
    }
}
```

### 6.4 `DdlDiffRenderer` (v1.1+ Forward Design)

```php
namespace SQLCraft\Contracts\DDL\Diff;

interface DdlDiffRendererInterface
{
    /**
     * Convert a SchemaDiff into an ordered list of executable DdlBuilder instances.
     * Ordering matters: table creates before FK adds that reference them; FK drops
     * before column drops that FKs depend on; index drops before column drops (some engines
     * require dropping a dependent index before the column it covers).
     *
     * @return list<DdlBuilderInterface>
     */
    public function render(SchemaDiff $diff, PlatformInterface $targetPlatform): array;
}
```

Planned v1.1 responsibilities: safe/unsafe change classification (e.g., narrowing a column's type or dropping a column is flagged `unsafe: true` since it is destructive/lossy), a `DryRunPreview` mode that renders SQL without an `execute()` path, and rename detection heuristics (a `columnsRemoved` + `columnsAdded` pair with matching type/position is *not* automatically treated as a rename — that inference is left to the caller or a future opt-in heuristic, since silently guessing wrong is worse than requiring an explicit rename operation).

---

## 7. Auto-Increment and Sequence Handling

Auto-generated primary key values are one of the most divergent DDL features across engines. SQLCraft's builders expose one concept — "this column auto-generates its value on insert" — via `ColumnMeta::$autoIncrement` (05-domain-model.md §4.1), and each platform renders the engine-appropriate mechanism.

| Engine | Mechanism | Rendering |
|--------|-----------|-----------|
| MySQL / MariaDB | `AUTO_INCREMENT` column attribute | `id INT NOT NULL AUTO_INCREMENT` inline in column definition; `CreateTableBuilder::$includeAutoIncrementValue` controls whether `AUTO_INCREMENT = N` table option is emitted (mirrors Adminer's `$auto_increment` param to `create_sql()` — used when exporting data-preserving dumps vs schema-only dumps) |
| PostgreSQL | `SERIAL`/`BIGSERIAL` pseudo-type (sugar for column + owned sequence + default), or explicit `GENERATED ALWAYS AS IDENTITY` (SQL-standard, preferred for new schemas) | `PostgreSQLPlatform` renders `GENERATED BY DEFAULT AS IDENTITY` by default; a platform option allows falling back to `SERIAL` for compatibility with older PgSQL (<10) |
| MSSQL | `IDENTITY(seed, increment)` column property | `id INT IDENTITY(1,1) NOT NULL` |
| SQLite | `INTEGER PRIMARY KEY` (aliases the rowid; auto-increments implicitly); `AUTOINCREMENT` keyword optionally added to guarantee monotonic non-reused values via the `sqlite_sequence` table | `SqlitePlatform` renders plain `INTEGER PRIMARY KEY` by default; a `ColumnMeta` flag (`strictMonotonicAutoIncrement`) opts into the `AUTOINCREMENT` keyword, matching SQLite's own documented tradeoff (slightly slower, guarantees no ID reuse) |
| Oracle | No native auto-increment before 12c; SQLCraft's `CreateSequenceBuilder` + `CreateTriggerBuilder` combination emulates it — a sequence plus a `BEFORE INSERT` trigger populating the column from `sequence.NEXTVAL`. Oracle 12c+ supports `GENERATED ALWAYS AS IDENTITY` directly | `OraclePlatform::renderCreateTable()` detects `autoIncrement` columns and, depending on `ServerVersion`, either emits the SQL-standard `IDENTITY` clause (12c+) or emits the column plain and returns an *additional* sequence + trigger pair via `renderAutoIncrementEmulation()` — a platform-internal helper invoked by `CreateTableBuilder::execute()` for Oracle specifically |

**Design decision:** the domain model does not introduce an `AutoIncrementStrategy` enum on `ColumnMeta` — the single boolean `autoIncrement` flag is the portable concept, and *how* it is achieved is entirely the platform's business. This keeps `ColumnMeta` engine-neutral (consistent with 05-domain-model.md's philosophy) while allowing Oracle's multi-object emulation to remain internal to `OraclePlatform`.

---

## 8. DDL Return Types and Execution

Every `DdlBuilderInterface` implementation supports two usage modes, matching the "preview or execute" requirement:

```php
$builder = new CreateTableBuilder(
    table: new QualifiedName(new Identifier('orders')),
    columns: [$idColumn, $customerIdColumn, $totalColumn],
    indexes: [$primaryKeyIndex],
    foreignKeys: [$customerFk],
);

// Preview: get SQL string(s) without touching the database — e.g., for export (14-import-export.md)
// or for a "review DDL before applying" UI workflow.
$statements = $builder->toSql($connection->getPlatform());
foreach ($statements as $sql) {
    echo $sql, ";\n";
}

// Execute: run through QueryExecutor, with events and cache invalidation.
$builder->execute($connection);
```

This mirrors the `create_sql()` (string-returning) vs actual execution split already implicit in Adminer's dump code (which calls `create_sql()` to get a string for the dump file, and separately calls `queries()` to execute DDL interactively) — but in SQLCraft both paths go through the same builder instance rather than being two different code paths that could drift out of sync.

---

## 9. Relation to Adminer's Approach — Summary Table

| Adminer construct | SQLCraft equivalent | Key improvement |
|-------------------|---------------------|------------------|
| `create_sql($table, $auto_increment, $style)` free function | `CreateTableBuilder` + `DdlDialectInterface::renderCreateTable()` | Intent (builder) separated from rendering (dialect); testable without a live connection |
| `alter_sql($table, $fields, $indexes, $foreign_keys, ...)` | `AlterTableBuilder` + `DdlDialectInterface::renderAlterTable()` | Per-engine branching lives in one method per platform, not one giant function with an engine switch |
| `truncate_sql($table)` | `TruncateBuilder` + `renderTruncate()` | Same behavior, typed builder instead of a bare string param |
| `trigger_sql($table)` | `CreateTriggerBuilder` (one per trigger) + `renderCreateTrigger()` | One builder per trigger object, not a function returning concatenated SQL for all triggers |
| `use_sql($database)` | `UseDatabaseBuilder` + `renderUseDatabase()` | Explicit builder; still a one-liner per platform |
| `add_field_sql()`, `modify_field_sql()`, `drop_field_sql()` | `AlterTableBuilder`'s `$addColumns`/`$modifyColumns`/`$dropColumns` + corresponding `render*` methods on the dialect | Unified into one ALTER TABLE builder rather than three separate free functions the caller must sequence manually |
| `index_sql()`, `foreign_key_sql()` | `CreateIndexBuilder`, `ForeignKeyMeta` + `renderAddForeignKey()` | Same |
| SQLite `recreate_table()` | `TableRecreationStrategy` | Transactional (§5.3), with an explicit FK-integrity check step Adminer does not perform |
| No diff/comparison feature | `SchemaDiff` / `TableDiff` / `ColumnDiff` (data model now; renderer in v1.1) | New capability beyond Adminer's scope — Adminer has no schema-diff feature at all |

---

## 10. Summary of Key Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| DDL architecture | Immutable builder VOs + platform-delegated rendering | Balances testability and composability against the complexity of a full AST; avoids Adminer's imperative string concatenation |
| Builder granularity | One builder class per DDL operation type | Each builder's properties map directly to that operation's parameters; no builder tries to do everything |
| `toSql()` return type | `list<string>` (not a single string) | Some operations (PgSQL multi-ALTER, SQLite recreation) inherently require multiple statements |
| SQLite ALTER TABLE | Dedicated `TableRecreationStrategy`, always transactional | Preserves atomicity that Adminer's non-transactional free-function approach lacks; adds a post-recreation FK integrity check |
| Schema diff scope | Data structures in v1; renderer deferred to v1.1 | Diff-to-DDL generation has enough edge cases (rename detection, safe/unsafe classification) to warrant its own design pass rather than rushing it into v1 |
| Auto-increment modeling | Single boolean flag on `ColumnMeta`; mechanism is platform-internal | Keeps the domain model engine-neutral; Oracle's sequence+trigger emulation stays encapsulated in `OraclePlatform` |
| Identifier quoting | Always quote, never attempt keyword detection/avoidance | Eliminates an entire class of cross-engine keyword-collision bugs; `getKeywordList()` remains available for advisory warnings only |
| Escape hatch | `rawDdl(string $sql)` on the dialect for exotic engine-specific DDL | Builders cover the common cases; raw SQL remains available without blocking on builder completeness |
| Execution path | All builders route through `QueryExecutor::executeDdl()` | Ensures events, cache invalidation, and MySQL auto-commit semantics are handled uniformly regardless of which builder was used |

