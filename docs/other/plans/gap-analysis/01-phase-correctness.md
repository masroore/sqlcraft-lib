# Phase 1 — Correctness: make green milestones actually true

> Depends on: nothing. Start immediately.
> Release-blocking: **yes.** These are "we said done, it isn't."
> Closes audit findings: B4, B5, B6, B9, B10 (summary); 03 §2.2/§4.2/§5; 04 §3.1/§4.2/§5.2; 06 findings 1/2/3.

This phase does not add features. It repairs code already shipped under a green
milestone that does not do what the milestone claims. Every item here is a
correctness bug behind a "done" checkbox.

---

## 1.1 SQL Server introspection — `SqlServerMetadataFactory`

**Problem:** `SchemaManagerFactory::metadataFactory()` has no `sqlserver` arm; the
`default` branch throws `InvalidArgumentException`. Any `SchemaManager` call on a
MSSQL connection dies at factory selection. M8 shipped the driver + platform but
introspection is non-functional. (Audit 03 §2.2, Critical.)

**Work:**
1. Create `src/Metadata/SqlServerMetadataFactory.php` extending `AbstractMetadataFactory` (mirror the 149-byte stubs `MySQLMetadataFactory`/`SqliteMetadataFactory` — they are thin subclasses; the introspection SQL lives in the platform's `IntrospectionDialectInterface`).
2. Confirm `SqlServerPlatform` implements every `getXxxSql()` method `AbstractMetadataFactory` invokes. Where MSSQL genuinely lacks a concept, the platform method must `throw $this->unsupported(Capability::X)`, not return broken SQL.
3. Add `'sqlserver' => new SqlServerMetadataFactory()` to the `match` in `SchemaManagerFactory`.
4. Add the SQL Server golden fixture: run the golden generator for `sqlserver`, commit `tests/Golden/fixtures/sqlserver-introspection.sql`, add `yield 'sqlserver'` to `IntrospectionSqlGoldenTest::platformProvider()`.

**Acceptance:** a `SchemaManager` built on a SQL Server connection lists tables,
columns, indexes, FKs, and views without throwing. Golden test covers all 17
introspection methods for `sqlserver`. `SqlServerIntegrationTest` extended to
exercise introspection, not just connect/query.

---

## 1.2 DDL execution routes through `QueryExecutor` (events + SQLite recreation)

**Problem:** All 18 DDL builder `execute()` methods call `$connection->execute($sql)`
directly, bypassing `QueryExecutor::executeDdl()`. Result: no `BeforeDdlExecuted`/
`AfterDdlExecuted` events, no cache invalidation, and — critically —
`AlterTableBuilder::execute()` on SQLite skips `TableRecreationStrategy` entirely.
Events and safe SQLite ALTER only work if the caller happens to route through
`DdlManager`. The fluent `$builder->execute($conn)` path (the natural one) is silently
wrong. (Audit 04 §4.2/§5.2, doc 13 §2.2 explicitly promises the routed behavior.)

**Decision — pick one (recommend A):**

- **A (recommended): make `DdlManager` the sole execution path.** Remove `execute()` from `DdlBuilderInterface`; builders expose only `toSql(PlatformInterface): string|array`. `DdlManager::execute(DdlBuilderInterface)` renders, routes through `QueryExecutor::executeDdl()` (fires events, invalidates cache), and applies `TableRecreationStrategy` for SQLite ALTER. Update `DatabaseSession::ddl()` (Phase 3) as the only advertised entry.
- **B (smaller diff, weaker): keep `execute()` but route it internally.** Each builder's `execute()` delegates to an injected `QueryExecutor`/`DdlManager` rather than the raw connection. More wiring per builder, easy to regress.

**Work (A):**
1. Remove `execute()` from `DdlBuilderInterface`; delete the 18 `execute()` bodies (keep `toSql()`).
2. Extend `DdlManager::execute()` to handle every builder type (it already special-cases SQLite `AlterTableBuilder`); ensure it always calls `QueryExecutor::executeDdl()` for the non-recreation path.
3. Add `getObjectName(): string` to `DdlBuilderInterface` so `DdlManager` emits the DDL target name (e.g. `"orders"`) in events instead of the reflection shortname `"CreateTableBuilder"` (Audit 04 §5.3).
4. Update all tests/examples that call `$builder->execute($conn)` to `$ddlManager->execute($builder)`.

**Acceptance:** every DDL operation fires `BeforeDdlExecuted`/`AfterDdlExecuted`;
SQLite `ALTER TABLE` drop-column/modify-type runs through recreation on all paths;
no builder references a raw connection; event object name is the DDL target.

---

## 1.3 Metadata cache invalidation listener

**Problem:** `MetadataCacheInterface` declares `invalidateTable/invalidateDatabase/clear`.
`SchemaChangedEvent` and `AfterDdlExecuted` are dispatched. But **no listener anywhere
calls any invalidate method.** The whole invalidation pathway is a dead circuit — after
DDL the cache serves stale metadata forever. (Audit 03 §4.2, High.)

**Work:**
1. Create `src/Schema/CacheInvalidationListener.php` (final): on `AfterDdlExecuted`/`SchemaChangedEvent`, read the affected database/table from the event and call `$cache->invalidateTable()` / `invalidateDatabase()`. Fall back to `clear()` only when the event carries no object scope.
2. Register the listener with the event dispatcher during `SchemaManager`/`SQLCraftFactory` construction (Phase 3 wires the factory; until then wire in `SchemaManagerFactory`).
3. This requires `DdlManager` events to actually fire — depends on §1.2.

**Acceptance:** a unit test dispatches `AfterDdlExecuted` for table `foo` and asserts
`invalidateTable(db, 'foo')` was called; an integration test does `CREATE`/`ALTER`
then confirms the next `getTable()` returns fresh metadata, not a cached stale copy.

---

## 1.4 Inspector capability gates

**Problem:** Zero inspectors call `$caps->require(...)`. Behavior on unsupported
platforms depends on whether the platform dialect happens to guard internally —
inconsistent: sometimes a `CapabilityNotSupportedException` from deep in the platform,
sometimes an empty collection, sometimes a raw DB error. (Audit 03 §5, High. Doc 09 +
doc 11 §7 require the explicit inspector-level gate.)

**Work:**
1. For each capability-gated inspector (`SequenceInspector`, `CheckConstraintInspector`, `TriggerInspector`, `RoutineInspector`, `UserInspector`, and the future `PrivilegeInspector`), resolve the `CapabilitySet` from the connection and call `$caps->require(Capability::X)` before issuing the dialect query. The capability per inspector is specified in doc 09.
2. Inspectors that are universal (table/column/index/FK/view/database/server) need no gate — leave them.

**Acceptance:** calling `getSequences()` on MySQL (no sequences) throws
`CapabilityNotSupportedException` from the inspector, deterministically, before any
SQL is issued. A test matrix asserts the throw per (inspector × unsupported engine).

---

## 1.5 MariaDB sequence rendering

**Problem:** `MariaDbPlatform` advertises `Capability::Sequence` for 10.3+ but inherits
`MySQLPlatform`'s `renderCreateSequenceStatement`/`renderDropSequenceStatement`, which
both `throw unsupported(Capability::Sequence)`. Capability check says yes, render throws.
(Audit 04 §3.1, High.)

**Work:**
1. Override `renderCreateSequenceStatement` / `renderDropSequenceStatement` in `MariaDbPlatform` using MariaDB syntax: `CREATE SEQUENCE name START WITH n INCREMENT BY n ...`, `DROP SEQUENCE [IF EXISTS] name`.
2. Also fix the incidental `MySQLPlatform` line-503 third `unsupported(Capability::Sequence)` throw (Audit 04 §3.3 / L3) — confirm the method it sits in or remove it.

**Acceptance:** on a MariaDB 10.3+ connection, `CreateSequenceBuilder` renders valid
SQL and executes; capability check and render agree. Golden/unit test for MariaDB
sequence SQL.

---

## 1.6 Import statement splitter (state machine over a stream)

**Problem:** `Importer` reads 8 KB chunks into a buffer and flushes on
`str_ends_with(rtrim($buffer), ';')`. This reintroduces the exact bug the plan claims to
kill: a value like `INSERT ... VALUES ('abc;')` flushes mid-statement, and a large single
statement grows the buffer unbounded (O(1) memory guarantee violated). `StatementSplitter`
currently takes a `string` and returns a materialized `StatementBatch`. (Audit 06 finding 1,
High. Doc 14 §6.2 promises a `resource`-reading generator state machine.)

**Work:**
1. Change `StatementSplitterInterface::split()` (in `src/Contracts/Execution/`) to `split(resource $stream, string $delimiter = ';'): \Generator` — yield one complete statement at a time, tracking string-literal / quote / comment / `DELIMITER` state so `;` inside literals does not split.
2. Rewrite `src/Query/StatementSplitter.php` (also moved to `src/Execution/` in Phase 8 §hygiene, but functional rewrite lands here) as the state machine.
3. Remove the chunk-accumulation loop and `endsWithStatementDelimiter()` from `Importer`; replace with `foreach ($this->splitter->split($stream, $delimiter) as $stmt) { ... }`.
4. Keep a `StatementBatch` convenience only if `BatchExecutor` still needs it; otherwise thread the generator straight into batch execution respecting `maxStatements`.

**Acceptance:** import of a file containing `;` inside string literals and a single
multi-megabyte statement completes correctly with bounded memory. A unit test feeds a
tricky fixture (literal semicolons, `DELIMITER $$` blocks, line/block comments) and
asserts the exact statement boundaries. The existing large-file memory-bound integration
test still passes.

---

## 1.7 Export flags + AllDatabases (correctness slice)

These two are export-pipeline correctness bugs behind M7-green. The full
import/export completeness work is Phase 6; the two dead-behavior fixes land here
because they make a green milestone honest.

**1.7a `DumpOptions` flags dead** — `includeTriggers/includeRoutines/includeEvents/
includeUserTypes` are declared but read by no export code, so that DDL is never emitted
regardless of flag. (Audit 06 finding 2, High.) Wire `TableDumper`/`Exporter` to read
each flag, emit the corresponding DDL via `ExportSourceInterface`, each guarded by the
matching capability. (Events/UserTypes DDL depends on Phase 7 builders existing; if Phase 7
defers events/types, this flag becomes a no-op with an explicit deferral note rather than a
silent dead flag.)

**1.7b `AllDatabases` → single-DB** — `Exporter::export()` routes both `Database` and
`AllDatabases` to `exportDatabase()`, which dumps only the connection's active DB. No
`ServerInspectorInterface::getDatabases()` loop. (Audit 06 finding 3, High.) Split the
`match` arm; add `exportAllDatabases()` iterating `getDatabases()` and calling
`exportDatabase()` per DB with section DDL between.

**Acceptance:** with `includeTriggers=true` the dump contains trigger DDL (capability
permitting); `DumpScope::allDatabases()` produces a multi-database dump. Tests cover both.

---

## Phase 1 exit criteria

- MSSQL introspection works end to end (1.1).
- All DDL fires events + SQLite recreation on every path (1.2).
- Cache invalidates after DDL (1.3).
- Inspectors gate on capability deterministically (1.4).
- MariaDB sequences render and execute (1.5).
- Import splitter is a streaming state machine (1.6).
- Export honors DDL flags and iterates all databases (1.7).
- `make build` and `make test` green; the M4/M5/M6/M7/M8 claims these back are now true.
