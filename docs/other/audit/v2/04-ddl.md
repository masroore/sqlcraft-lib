# Audit 04 — DDL Services

> Auditor: automated read-only scan
> Date: 2026-07-21
> Sources examined: docs/plans/13-ddl-services.md, docs/plans/04-feature-inventory.md §2–13,
> docs/plans/07-module-breakdown.md §9, docs/plans/18-public-api.md (DdlManager),
> src/DDL/\*, src/DDL/Definition/\*, src/DDL/Sqlite/\*, src/Contracts/DDL/\*,
> src/Contracts/Platform/DdlDialectInterface.php, src/Platform/\*Platform.php,
> tests/Unit/DDL/\*, tests/Integration/DDL/\*

---

## 1. Builder Inventory vs Roadmap

### 1.1 Roadmap builders present (doc 13 §2.3)

All 19 builders listed in the roadmap table exist as concrete classes under `src/DDL/`:

`AlterTableBuilder`, `CreateDatabaseBuilder`, `CreateIndexBuilder`, `CreateRoutineBuilder`,
`CreateSchemaBuilder`, `CreateSequenceBuilder`, `CreateTableBuilder`, `CreateTriggerBuilder`,
`CreateViewBuilder`, `DropDatabaseBuilder`, `DropIndexBuilder`, `DropRoutineBuilder`,
`DropSchemaBuilder`, `DropSequenceBuilder`, `DropTableBuilder`, `DropTriggerBuilder`,
`DropViewBuilder`, `TruncateBuilder`, `UseDatabaseBuilder`.

---

### 1.2 Builders promised in doc 04 but absent from both plan §2.3 and codebase

These were silently dropped when doc 13 narrowed the scope. Each is promised in the feature
inventory, maps to the DDL module, and has no placeholder or `@todo`.

| # | Missing builder | Promise (doc + section) | Severity |
|---|---|---|---|
| 1 | `AlterDatabaseBuilder` | doc 04 §2: "Alter database (charset/collation) — DDL, Capability::DatabaseManagement" | HIGH |
| 2 | `RenameDatabaseBuilder` | doc 04 §2: "Rename database — DDL, Capability::DatabaseRename" | HIGH |
| 3 | `CopyTableBuilder` | doc 04 §3: "Copy table — DDL, Capability::TableCopy" | HIGH |
| 4 | `MoveTableBuilder` | doc 04 §3: "Move table (between DBs/schemas) — DDL, Capability::TableMove" | HIGH |
| 5 | `AlterViewBuilder` | doc 04 §8: "Create/alter/drop view — DDL, baseline" (ALTER arm absent) | MEDIUM |
| 6 | `AlterRoutineBuilder` | doc 04 §9: "Create/alter/drop procedure — DDL, Capability::StoredProcedures" (ALTER arm absent) | MEDIUM |
| 7 | `AlterTriggerBuilder` | doc 04 §10: "Create/alter/drop trigger — DDL, Capability::Triggers" (ALTER arm absent) | MEDIUM |
| 8 | `CreateEventBuilder` / `DropEventBuilder` / `AlterEventBuilder` | doc 04 §11: "Create/alter/drop event — DDL, Capability::Events" — all three absent | HIGH |
| 9 | `CreateTypeBuilder` / `DropTypeBuilder` / `AlterTypeBuilder` | doc 04 §13: "Create/alter/drop user-defined type — DDL, Capability::UserDefinedTypes" — all three absent | HIGH |

**Recommended fix for each:** Raise an explicit tracking issue (or ADR) deciding whether
these are deferred to a later milestone or removed from scope. The current state — promised
in doc 04, absent from doc 13, absent from code, no note — is a silent gap that will surface
as missing functionality at integration time.

---

## 2. DdlDialectInterface — Plan vs Reality

### 2.1 Method name and signature divergence

Doc 13 §3 specifies the interface as receiving typed builder VOs (e.g.,
`renderCreateTable(CreateTableBuilder $builder): string`). The shipped
`DdlDialectInterface` instead uses decomposed parameters and different method names:

| Plan §3 method | Actual interface method | Delta |
|---|---|---|
| `renderCreateTable(CreateTableBuilder)` | `renderCreateTableStatement(QualifiedName, array, array, array)` | Name changed; VO replaced by decomposed params |
| `renderDropTable(DropTableBuilder)` | `renderDropTableStatement(QualifiedName, bool, bool)` | Name changed; VO replaced |
| `renderAlterTable(AlterTableBuilder): array` | `renderDdlAlterTable(AlterTableDefinitionInterface): array` | Name changed; concrete VO replaced by interface |
| `renderCreateView(CreateViewBuilder)` | `renderCreateViewStatement(QualifiedName, string, bool, array, ?string)` | Name changed; VO replaced |
| `renderDropView(DropViewBuilder)` | `renderDropViewStatement(QualifiedName, bool, bool)` | Name changed; VO replaced |
| `renderTruncate(TruncateBuilder)` | `renderTruncateStatement(QualifiedName, bool, bool)` | Name changed; VO replaced |
| `renderCreateIndex(CreateIndexBuilder)` | `renderCreateIndexStatement(QualifiedName, IndexMeta)` | VO replaced; also `renderDdlCreateIndexStatement(QualifiedName, IndexDefinitionInterface)` exists (dual) |
| `renderDropIndex(DropIndexBuilder)` | `renderDropIndexStatement(QualifiedName, Identifier)` | VO replaced |
| `renderUseDatabase(UseDatabaseBuilder)` | `renderUseDatabaseStatement(Identifier)` | Name changed; VO replaced |

**Severity:** MEDIUM. The decomposed-parameter approach is a legitimate implementation
choice, but it contradicts the plan's "builder as typed parameter" design contract and means
the interface no longer documents which builder properties are consumed per operation.

**Fix:** Update doc 13 §3 to reflect the shipped signature pattern. If the VO-receiving
design is still preferred, migrate the interface in a single pass with concrete platform
changes; do not patch ad-hoc.

### 2.2 Methods present in plan §3 but absent from interface

The plan lists these as `DdlDialectInterface` methods; none appear in the actual interface:

- `renderAddColumn(QualifiedName, ColumnMeta, ?string)` — plan §3 "Constraint DDL" block
- `renderModifyColumn(QualifiedName, ColumnMeta, ColumnMeta)` — plan §3
- `renderDropColumn(QualifiedName, Identifier)` — plan §3
- `renderAddForeignKey(QualifiedName, ForeignKeyMeta)` — plan §3
- `renderDropForeignKey(QualifiedName, Identifier)` — plan §3
- `renderAddCheckConstraint(QualifiedName, CheckConstraintMeta)` — plan §3
- `renderDropCheckConstraint(QualifiedName, Identifier)` — plan §3

`AbstractPlatform` provides concrete implementations of these as public methods, but they
are not part of the contract and cannot be called through `DdlDialectInterface`.

**Severity:** MEDIUM. Any code using `DdlDialectInterface $dialect` cannot call these
methods without downcasting. Fix: add them to the interface, or document that
`AbstractPlatform` is the practical minimum type.

### 2.3 Duplicate render method pairs (in-progress migration artifact)

`DdlDialectInterface` contains parallel pairs for the same concept:

| DTO-based method | Interface-based method |
|---|---|
| `renderColumnDefinition(ColumnMeta)` | `renderDdlColumnDefinition(ColumnDefinitionInterface)` |
| `renderPrimaryKeyClause(IndexMeta)` | `renderDdlPrimaryKeyClause(IndexDefinitionInterface)` |
| `renderForeignKeyClause(ForeignKeyMeta)` | `renderDdlForeignKeyClause(ForeignKeyDefinitionInterface)` |
| `renderCheckConstraintClause(CheckConstraintMeta)` | `renderDdlCheckConstraintClause(CheckConstraintDefinitionInterface)` |
| `renderCreateIndexStatement(QualifiedName, IndexMeta)` | `renderDdlCreateIndexStatement(QualifiedName, IndexDefinitionInterface)` |

`AbstractPlatform` bridges them: `renderDdlCreateIndexStatement` calls `toIndexMeta()` and
delegates to `renderCreateIndexStatement`. This suggests an incomplete migration from the
DTO-based style to the definition-interface style.

**Severity:** LOW. Both paths work but callers must know which to use. Pick one and
deprecate the other before the interface stabilises.

---

## 3. Platform DDL Method Coverage (DdlDialectInterface compliance)

All five shipped platforms (MySQL, MariaDB, PostgreSQL, SQLite, SqlServer) implement the
full `DdlDialectInterface` via `AbstractPlatform` inheritance. Platform-specific overrides
and unsupported-capability guards are present for the major divergences. Specific findings:

### 3.1 MariaDB — Sequence capability advertised, render methods absent

`MariaDbPlatform` lists `[Capability::Sequence, [10, 3, 0]]` in its version-gated
capability matrix (MariaDB 10.3+ correctly advertises sequence support). However,
`MariaDbPlatform` has **zero** overrides of `renderCreateSequenceStatement` or
`renderDropSequenceStatement`; it inherits `MySQLPlatform`'s implementations, which both
`throw $this->unsupported(Capability::Sequence)`.

**Result:** On a MariaDB 10.3+ connection, `getCapabilitySet()->has(Capability::Sequence)`
returns `true`, but `CreateSequenceBuilder::toSql()` and `execute()` immediately throw
`CapabilityNotSupportedException`.

**Severity:** HIGH. Capability advertisement and render behavior are contradictory; any
code doing a capability check before building will reach a hard throw it did not anticipate.

**Fix:** Add `renderCreateSequenceStatement` / `renderDropSequenceStatement` overrides to
`MariaDbPlatform` using MariaDB's `CREATE SEQUENCE name START WITH n INCREMENT BY n`
syntax, or (if deferring) remove the version-gated `Capability::Sequence` entry until the
render methods exist.

### 3.2 SQLite — `renderCreateSchemaStatement` not overridden

`SqlitePlatform` does not override `renderCreateSchemaStatement`. SQLite has no `CREATE SCHEMA`
statement. Calling `$createSchemaBuilder->toSql($sqlitePlatform)` falls through to
`AbstractPlatform::renderCreateSchemaStatement()`, which produces SQL SQLite will reject at
execution time.

`CreateSchemaBuilder::execute()` correctly guards with
`->require(Capability::Scheme)` before running, so the `execute()` path is safe. But the
`toSql()` preview path (used in `DdlManager::preview()` and any export pipeline) silently
produces invalid SQL for SQLite.

**Severity:** MEDIUM. `toSql()` is explicitly documented as a safe preview path; returning
silently broken SQL violates that contract.

**Fix:** Add `SqlitePlatform::renderCreateSchemaStatement()` that
`throw $this->unsupported(Capability::Scheme)`, consistent with other unsupported
operations in that class.

### 3.3 MySQL/MariaDB — `renderCreateSequenceStatement` at line 503

`MySQLPlatform` has a third unsupported throw at line 503 in addition to the two sequence
render methods (lines 223, 229). The extra throw suggests a copy-paste from the sequence
block into a different context. Should be confirmed as intentional or removed.

**Severity:** LOW.

### 3.4 SqlServer — Sequence delegated to parent

`SqlServerPlatform::renderCreateSequenceStatement()` calls `parent::renderCreateSequenceStatement()`
(AbstractPlatform). MSSQL supports `CREATE SEQUENCE` (since SQL Server 2012) and the
capability matrix includes `Capability::Sequence` for SqlServer. Verify
`AbstractPlatform::renderCreateSequenceStatement()` emits ANSI-standard `CREATE SEQUENCE`
syntax compatible with MSSQL; if it does not, SqlServer needs its own override.

**Severity:** LOW (needs manual verification of AbstractPlatform default).

---

## 4. SQLite `TableRecreationStrategy` — Wiring and Bypass Risk

### 4.1 Strategy is correctly wired into DdlManager

`DdlManager::execute()` checks `$builder instanceof AlterTableBuilder && $connection->getPlatformName() === 'sqlite'` and delegates to `TableRecreationStrategy::execute()` rather than the normal SQL-execute path. The strategy class exists at `src/DDL/Sqlite/TableRecreationStrategy.php` and is injected as an optional constructor argument. The integration test at `tests/Integration/DDL/SqliteTableRecreationIntegrationTest.php` covers the path.

### 4.2 Direct `AlterTableBuilder::execute()` bypasses recreation entirely

`AlterTableBuilder::execute()` calls `$connection->execute($sql)` directly, making no check
for SQLite and no call to `TableRecreationStrategy`. Any caller that uses the builder's own
`execute()` method instead of routing through `DdlManager::execute()` will:

1. Ask SQLite to run standard `ALTER TABLE` SQL for unsupported operations (drop column,
   modify type, add FK, etc.).
2. Receive a SQLite error at runtime with no data loss only because SQLite rejects the
   statement — but the caller has no indication they used the wrong path.

The public API in doc 18 exposes `DatabaseSession::ddl()->execute($builder)` (i.e., via
`DdlManager`), so the happy path is safe. However, `DdlBuilderInterface::execute()` is also
a public method and there is no deprecation notice, guard, or comment warning against
direct use on SQLite.

**Severity:** HIGH. Silent incorrect behaviour is possible through a documented public
method.

**Fix:** Either (a) remove `execute()` from `DdlBuilderInterface` and make `DdlManager` the
sole execution path (preferred — consistent with doc 13's "all execute() calls route through
QueryExecutor"), or (b) add a platform check inside `AlterTableBuilder::execute()` that
detects SQLite and throws a `\LogicException("Use DdlManager::execute() for SQLite ALTER TABLE")`.

---

## 5. Event Firing — `BeforeDdlExecuted` / `AfterDdlExecuted`

### 5.1 DdlManager fires events correctly

`DdlManager::execute()` calls `$this->events?->beforeDdlExecuted()` before and
`$this->events?->afterDdlExecuted()` after each SQL statement, matching the plan (doc 13 §2.2).
It also calls `beforeSchemaChange()` / `schemaChanged()` from `SchemaEventDispatcherInterface`,
which is an addition beyond the plan but not contradictory.

### 5.2 Direct builder `execute()` fires no events

As established in §4.2, all 18 builder `execute()` methods call `$connection->execute($sql)`
directly, completely bypassing `QueryExecutor::executeDdl()` and any event dispatcher.

The plan (doc 13 §2.2) explicitly states:
> "execute() routes through QueryExecutor::executeDdl() which fires BeforeDdlExecuted /
> AfterDdlExecuted events, invalidates the metadata cache..."

This is not what the code does. The builder's `execute()` is a thin wrapper around
`$connection->execute()` with no event plumbing.

**Severity:** HIGH. Any consumer calling `$builder->execute($connection)` directly
(the natural fluent pattern) silently skips all events, cache invalidation, and any
registered observers.

**Fix:** Align with the plan: either route builder `execute()` through `QueryExecutor`, or
(simpler) make builder `execute()` `final` and `@internal`, and ensure the public-facing
`DatabaseSession::ddl()` API is the only advertised path. Add a `@throws \LogicException`
note to `DdlBuilderInterface::execute()` with a migration message if deprecating.

### 5.3 DdlManager event object name uses reflection shortname

`DdlManager::objectName()` returns `(new \ReflectionClass($builder))->getShortName()` (e.g.,
`"CreateTableBuilder"`). This gives event consumers a class name, not a table/object name.
The plan implies the object name should be the DDL target (e.g., `"orders"` for a
`CreateTableBuilder` on the `orders` table). Minor but may confuse event consumers.

**Severity:** LOW. Fix: add `getObjectName(): string` to `DdlBuilderInterface` and implement
on each builder to return the qualified target name.

---

## 6. Capability-Gated Builders — Execute-Time Throw Consistency

| Builder | Platform that must throw | Throw location | Correct? |
|---|---|---|---|
| `CreateSequenceBuilder` | MySQL (no sequences) | `MySQLPlatform::renderCreateSequenceStatement()` → at `toSql()` time | Partially — throws at `toSql()`, not `execute()` as plan states |
| `CreateSchemaBuilder` | SQLite (no schemas) | `CreateSchemaBuilder::execute()` capability guard | Correct for `execute()`; `toSql()` path is silent (§3.2) |
| `CreateRoutineBuilder` | SQLite | `SqlitePlatform::renderCreateRoutineStatement()` → throws at `toSql()` | Consistent |
| `CreateTriggerBuilder` | No platform throws | `AbstractPlatform` has no guard; SQLite supports triggers natively | Correct |

`CreateSchemaBuilder` is the **only** builder with a runtime capability guard in `execute()`.
All others surface the capability failure via the platform renderer at `toSql()` time. This
inconsistency is low-risk (the throw still surfaces) but worth standardising.

**Severity:** LOW.

---

## 7. Dead / Orphaned Code Check

All seven `Definition/` classes (`CheckConstraintDefinition`, `ColumnDefinition`,
`ForeignKeyDefinition`, `IndexColumnDefinition`, `IndexDefinition`,
`RoutineParameterDefinition`, `TableRecreationDefinition`, `TriggerDefinition`) are
referenced by tests in `tests/Unit/DDL/DefinitionTest.php`,
`tests/Unit/DDL/TriggerRoutineBuilderTest.php`, and
`tests/Unit/DDL/TableRecreationStrategyTest.php`. No orphaned Definition classes found.

`TableRecreationStrategy` is referenced in `DdlManager` (constructor injection and
`execute()` dispatch) and in `TableRecreationStrategyTest` and
`SqliteTableRecreationIntegrationTest`. Not dead.

No dead or orphaned DDL code identified.

---

## 8. Summary of Findings by Severity

### HIGH (5 findings)

| # | Finding | File / Absence | One-line fix |
|---|---|---|---|
| H1 | 5 high-impact builder groups missing: `AlterDatabase`, `RenameDatabase`, `CopyTable`, `MoveTable`, `CreateEvent`/`DropEvent`/`AlterEvent`, `CreateType`/`DropType`/`AlterType` | `src/DDL/` — all absent | Create tracking issues; either schedule or explicitly drop from doc 04 scope |
| H2 | All builder `execute()` methods bypass `QueryExecutor`, firing no events and no cache invalidation | `src/DDL/*.php` — every builder | Route through `QueryExecutor::executeDdl()` or mark builder `execute()` internal |
| H3 | `AlterTableBuilder::execute()` bypasses `TableRecreationStrategy` on SQLite | `src/DDL/AlterTableBuilder.php:107` | Add SQLite platform check in `execute()` or remove `execute()` from `DdlBuilderInterface` |
| H4 | MariaDB 10.3+ advertises `Capability::Sequence` but render methods throw `CapabilityNotSupportedException` | `src/Platform/MariaDbPlatform.php` (missing overrides) | Add `renderCreateSequenceStatement` / `renderDropSequenceStatement` to `MariaDbPlatform` |
| H5 | 4 ALTER builders missing for features listed as "create/alter/drop" in doc 04: view, routine, trigger (ALTER arms); all three absent | `src/DDL/` — absent | Add `AlterViewBuilder`, `AlterRoutineBuilder`, `AlterTriggerBuilder` or document as out-of-scope |

### MEDIUM (4 findings)

| # | Finding | File | One-line fix |
|---|---|---|---|
| M1 | `DdlDialectInterface` method names and signatures contradict doc 13 §3 (VO-receiving contract vs decomposed params) | `src/Contracts/Platform/DdlDialectInterface.php` | Update doc 13 §3 to match shipped signatures |
| M2 | 7 per-column/constraint render methods in plan §3 absent from `DdlDialectInterface` (only on `AbstractPlatform`) | `src/Contracts/Platform/DdlDialectInterface.php` | Add to interface or document `AbstractPlatform` as minimum concrete type |
| M3 | `SqlitePlatform::renderCreateSchemaStatement()` missing — `toSql()` silently returns invalid SQL | `src/Platform/SqlitePlatform.php` | Add override: `throw $this->unsupported(Capability::Scheme)` |
| M4 | 5 duplicate render-method pairs in interface (DTO-based and interface-based variants co-exist) | `src/Contracts/Platform/DdlDialectInterface.php` | Choose one style and deprecate the other |

### LOW (3 findings)

| # | Finding | File | One-line fix |
|---|---|---|---|
| L1 | Capability throw timing inconsistency: most builders throw at `toSql()` (platform renderer), `CreateSchemaBuilder` at `execute()` | `src/DDL/CreateSchemaBuilder.php` vs others | Standardise: add `execute()` guards to all capability-gated builders, or remove the one in `CreateSchemaBuilder` |
| L2 | `DdlManager::objectName()` returns class shortname instead of DDL target name in events | `src/DDL/DdlManager.php:56` | Add `getObjectName(): string` to `DdlBuilderInterface` |
| L3 | `MySQLPlatform` has a third `throw $this->unsupported(Capability::Sequence)` at line 503 separate from the two sequence render methods | `src/Platform/MySQLPlatform.php:503` | Verify it is in a named method that warrants the throw, or remove |
