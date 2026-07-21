# 04 — Schema Introspection & DDL Services Audit

> **Status:** Final
> **Audit date:** 2026-07-21
> **Baseline:** `master @ 6d50506`
> **Plans reviewed:** `11-schema-services.md`, `13-ddl-services.md`
> **Implementation reviewed:** `src/Metadata/`, `src/Schema/`, `src/DDL/`, `src/Contracts/Metadata/`, `src/Contracts/DDL/`, `src/Contracts/Schema/`, and the introspection/DDL portions of `src/Platform/`

---

## 1. Gaps

- **CRITICAL — Oracle platform entirely absent.** No `OraclePlatform`, no Oracle introspection dialect, no Oracle metadata factory; `grep "Oracle"` over `src/` returns nothing. Plan 11 §2 specifies Oracle's `ALL_*` catalog strategy and plan 13 §7 designs `OraclePlatform::renderCreateTable()` + `renderAutoIncrementEmulation()` (sequence + trigger). Six platforms were planned; five exist (`AbstractPlatform`, `MySQLPlatform`, `MariaDbPlatform`, `PostgreSQLPlatform`, `SqlServerPlatform`, `SqlitePlatform`). (Project-wide deferral per PROGRESS.md M8 T4 — still a gap against these plans. Cross-ref: [02](02-driver-platform-capabilities.md).)

- **MODERATE — `TableSearchService` missing (plan 11 §8).** No `TableSearchServiceInterface`, no `SearchResult` DTO, no implementation anywhere in `src/`. The entire generator-based search-across-tables service is absent. (Also the feature-inventory orphan in [08](08-testing-performance-roadmap.md).)

- **MODERATE — 3 of 4 cache implementations missing (plan 11 §5).** Only `NullMetadataCache` (`src/Schema/NullMetadataCache.php`) exists. `InMemoryMetadataCache`, `Psr6MetadataCache`, `Psr16MetadataCache` are not present.

- **MODERATE — Cache-invalidation contract unwired (plan 11 §5 "Invalidation contract").** `invalidateTable()`/`invalidateDatabase()` are declared on `MetadataCacheInterface` and no-op'd in `NullMetadataCache`, but **nothing in `src/` ever calls them**. `DdlManager::execute()` fires `schemaChanged` but never invalidates the metadata cache, so stale reads after DDL are not prevented in-library.

- **MODERATE — `PrivilegeInspector` has no implementation (plan 11 §3.12).** `PrivilegeInspectorInterface` exists (`src/Contracts/Metadata/PrivilegeInspectorInterface.php`) but there is no concrete class, no `getPrivileges()` on `SchemaManager`, and it is not wired in `SchemaManagerFactory`. (Note: `Capability::Privileges` *is* declared for MySQL and SqlServer in their capability matrices.)

- **MODERATE — MSSQL introspection not wired (plan 11 §6).** `SqlServerPlatform` (name `'sqlserver'`, `SqlServerPlatform.php:44`) supplies full introspection SQL (`getTablesSql`, `getAllColumnsSql`, etc.), but there is no `SqlServerMetadataFactory` (only MySQL/PgSQL/Sqlite factories in `src/Metadata/`), and `SchemaManagerFactory::metadataFactory()` matches only `mysql|mariadb|pgsql|sqlite` and throws `InvalidArgumentException` for `sqlserver`. MSSQL introspection cannot be assembled through the documented factory path.

- **MODERATE — Schema-diff data structures missing (plan 13 §6.2–6.3).** `SchemaDiff`, `TableDiff`, `ColumnDiff` are absent (grep finds nothing). Plan 13 §6.1 places the *data structures* in v1 scope (only the renderer is deferred to v1.1), so the structures are a gap. (`DdlDiffRendererInterface` being absent is expected — v1.1.)

- **MODERATE — SQLite recreation diverges from plan 13 §3.1/§5.2/§5.4.**
  (a) No `needsRecreation()` discrimination: `DdlManager::execute()` unconditionally routes **every** SQLite `AlterTableBuilder` through full recreation, so a simple `ADD COLUMN`/`RENAME` (natively supported, plan §5.4) triggers a full table rebuild.
  (b) The column-rename copy map (`buildColumnMap`, plan §5.2 step 3) is not implemented — `TableRecreationStrategy::execute()` uses the *same* `$columnList` for both the INSERT target and the SELECT source (`src/DDL/Sqlite/TableRecreationStrategy.php`), so a column rename combined with a recreation-requiring change would `SELECT` a not-yet-existent column name.

- **MODERATE — MySQL `AFTER` column positioning + batched ALTER not implemented (plan 13 §3.1).** No platform overrides `renderDdlAlterTable` (only `AbstractPlatform.php:223` defines it). The default throws `unsupported(Capability::MoveColumn)` when an `$after` position is supplied — yet MySQL declares `Capability::MoveColumn` (`MySQLPlatform.php:83`) and plan §3.1 shows `ADD COLUMN … AFTER`. The default also emits **one statement per change**, not the single multi-clause `ALTER TABLE` the plan specifies for MySQL (losing the atomicity/efficiency the plan highlights).

- **MINOR — MSSQL default-constraint drop before column drop not implemented (plan 13 §3.1).** `renderDdlAlterTable` passes only an `Identifier` to `renderAlterTableDropColumn`, discarding `defaultConstraintName`, so the plan's "drop named DEFAULT constraint first" step cannot occur.

- **MINOR — `rawDdl(string $sql)` escape hatch missing (plan 13 §1.2/§10).** Not present on `DdlDialectInterface`.

- **MINOR — `CreateTableBuilder::fromTableMeta()` / `withTable()` missing** (referenced in plan 13 §5.2). Recreation constructs the builder directly instead.

- **MINOR — SQLite `TRUNCATE` ignores `$restartIdentity`/`$cascade`.** `SqlitePlatform.php:254` returns bare `DELETE FROM`; plan 13 §3.2 calls for `sqlite_sequence` reset when `$restartIdentity`.

- **MINOR — Batched index/FK inspection only half-exposed.** `getAllIndexesSql`/`getAllForeignKeysSql` exist on every platform and the dialect interface, but no inspector method consumes them; only `getAllColumns` is surfaced (`ColumnInspector.php:42`, `SchemaManager.php:153`).

## 2. Drift

- **MODERATE (borderline CRITICAL on SQLite) — Builder `execute()` bypasses `QueryExecutor::executeDdl()`.** Plan 13 §2.2 note/§8/§10 state builders route through `executeDdl()` and "Builders never call `ConnectionInterface::execute()` directly." In fact **all 19 builders** call `$connection->execute($sql)` directly (e.g., `CreateTableBuilder::execute()`, `AlterTableBuilder::execute()`), skipping events, cache invalidation, and MySQL auto-commit handling. The proper path (`executeDdl` + events + SQLite recreation) exists only via `DdlManager::execute()`. Consequence: calling `$alterBuilder->execute($conn)` directly on SQLite emits invalid SQL (`ALTER TABLE … ALTER COLUMN` / `DROP CONSTRAINT` from the inherited `renderDdlAlterTable`), because recreation is dispatched solely by `DdlManager`.

- **MINOR (sanctioned) — Dialect interface takes decomposed args/projections, not builder VOs (plan 13 §3).** Plan §3 sketches `renderCreateTable(CreateTableBuilder)`, `renderAlterTable(AlterTableBuilder)`, etc. The implementation uses `renderCreateTableStatement(QualifiedName, array $columnClauses, array $constraintClauses, array $tableOptions)`, `renderDdlAlterTable(AlterTableDefinitionInterface)`, plus parallel `renderDdl*` projection methods (`src/Contracts/Platform/DdlDialectInterface.php`). This is explicitly acknowledged by plan 13 §1.1's note on `Contracts\DDL\*DefinitionInterface` projections, so it is a plan-internal inconsistency more than a true violation.

- **MINOR — Granular constraint/column render methods consolidated.** Plan §3's `renderModifyColumn`, `renderAddForeignKey`, `renderDropForeignKey`, `renderAddCheckConstraint`, `renderDropCheckConstraint` are not separate dialect methods; modify/FK/check add+drop are inlined in `AbstractPlatform::renderDdlAlterTable`. Only `renderAlterTableAddColumn`/`renderAlterTableDropColumn` survive as discrete methods.

- **MINOR — Cache-key shape differs.** Plan 11 §5 prescribes `{platform}/{database}/{schema}/{object}/{method}`; `SchemaManager::cacheKey()` uses `{platform}/{database}/{method}` with the object name embedded in the method token.

- **MINOR — DTO naming:** plan's `RoutineParamMeta` (§3.8) is implemented as `src/DTO/RoutineParameter.php`. (Cross-ref: [01](01-domain-model.md).)

## 3. Extras

- **`DdlManager` (`src/DDL/DdlManager.php`)** — not named in either plan. Provides `preview()`/`execute()`, the SQLite-recreation dispatch, and event firing (`beforeSchemaChange`/`beforeDdlExecuted`/`afterDdlExecuted`/`schemaChanged`). It effectively implements the execution wiring the plan assigned to the builders themselves (see Drift above).
- **Batched inspection** — `ColumnInspector::getAllColumns()` + `SchemaManager::getAllColumns()` and the `getAllColumnsSql`/`getAllIndexesSql`/`getAllForeignKeysSql` dialect methods go beyond plan 11 (which only specifies `streamTables()`).
- **`SchemaManager::describeTable()` + `TableStructure` DTO** (`src/Schema/TableStructure.php`) — realizes the "hypothetical `getTableFull()`" all-in-one mentioned only hypothetically in plan 11 §4.
- **Definition projection layer** — `src/DDL/Definition/*` and `src/Contracts/DDL/*DefinitionInterface` (+ `TableRecreationMetadataProviderInterface`); sanctioned by plan 13 §1.1 but additional structure beyond the §2/§3 sketches.
- **`BackwardKeyMeta` DTO** (`src/DTO/BackwardKeyMeta.php`) — defined but referenced nowhere else (orphan); the plan's "backward key" concept is served by `getReferencingKeys()` returning `ForeignKeyCollection`. (Cross-ref: [01](01-domain-model.md).)
- **`ExportSource` in `src/Metadata/`** — an export concern outside plan 11's scope (belongs to the import/export plan; see [06](06-import-export.md)).
- **Events integration** in `SchemaManager` (`metadataFetched`) and `DdlManager` — additive, from the events plan; note `SchemaManagerFactory` does not actually wire the `events` dependency into `SchemaManager`.
- **`getExplainSql`/`wrapWithTimeout`** on `IntrospectionDialectInterface` — execution-layer extras.

## 4. Faithful to Plan

- **All 13 planned inspector contracts exist** in `src/Contracts/Metadata/` with return types matching plan 11 §3 (`TableCollection`, `ColumnCollection`, `IndexCollection`, `ForeignKeyCollection`, `ViewCollection`, `RoutineCollection`, `TriggerCollection`, `SequenceCollection`, `CheckConstraintCollection`, `UserCollection`, `SchemaCollection`, `TypeCollection`, etc.).
- **All 19 planned DDL builders exist** in `src/DDL/` with the planned options (plan 13 §2.3): table/index/alter/view/trigger/routine/sequence/database/schema/select-database builders.
- **`DdlBuilderInterface`** (`toSql(): list<string>` + `execute()`) matches plan 13 §2.1 exactly.
- **Metadata-cache seam and `streamTables()` generator** match plan 11 (generator-based streaming inspection).
- **SQLite `TableRecreationStrategy`** follows plan 13 §5 in shape: transactional, FK-integrity check, temp-table swap — the gaps above are in discrimination and column mapping, not the core strategy.
- **Per-platform metadata factories** (MySQL/MariaDB/PgSQL/Sqlite) implement the plan 11 §6 / 05 §8 hydrator split.

## 5. Summary

The implementation faithfully realizes the *surface* of both plans: every planned inspector contract and all 19 DDL builders exist with matching options and return types, the metadata-cache seam and `streamTables()` generator match plan 11, and the SQLite `TableRecreationStrategy` follows plan 13 §5. The significant shortfalls are in *coverage and wiring*: Oracle is entirely absent, MSSQL introspection is not wired through the factory, `TableSearchService`/`PrivilegeInspector`/three cache implementations/the schema-diff structures are missing, and — most consequentially — builder `execute()` calls `ConnectionInterface::execute()` directly (contradicting plan 13's explicit invariant), so events, cache invalidation, and SQLite table recreation only fire through the unplanned `DdlManager`, and a direct `AlterTableBuilder::execute()` on SQLite produces invalid SQL.
