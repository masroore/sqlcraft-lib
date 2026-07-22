# 06 — Import / Export Audit

> **Status:** Final
> **Audit date:** 2026-07-21
> **Baseline:** `master @ 6d50506`
> **Plans reviewed:** `14-import-export.md`
> **Implementation reviewed:** `src/Export/`, `src/Import/`, `src/Contracts/Export/`, `src/Contracts/Import/`, import/export events in `src/Events/`, `src/Metadata/ExportSource.php`, `tests/Integration/ImportExport/`

---

## 1. Gaps

1. **CRITICAL — No built-in import sources.** Plan 14 §6.1 specifies four (`FileImportSource`, `StreamImportSource`, `StringImportSource`, `Psr7StreamImportSource`). Only the port exists (`src/Contracts/Import/ImportSourceInterface.php`); `grep "implements ImportSourceInterface"` in `src/` returns **zero** matches. Proof: `tests/Integration/ImportExport/ImportExportRoundTripTest.php` had to hand-roll two anonymous `ImportSourceInterface` classes because none ship. The library has no usable import input out of the box.

2. **CRITICAL — No compression anywhere.** Plan 14 §2.1 (`GzipSink`/`Bzip2Sink` decorators, `ExtensionMissingException`) and §6.1 (transparent `.gz`/`.bz2` decompression with magic-byte detection), plus the §9 contrast table, all require it. Only `ResourceSink` and `StringBufferSink` exist in `src/Export/`. No gzip/bzip2 on export *or* import.

3. **MODERATE — JSON / XML writers missing.** Plan 14 §5 defines `JsonFormatWriter` + `XmlFormatWriter`. `src/Export/` has only `SqlFormatWriter`, `CsvFormatWriter`, `CsvSemicolonFormatWriter`, `TsvFormatWriter`. (Consistent with open-Q 4.2's deferral — but a gap against plan 14 §5.)

4. **MODERATE — `includeTriggers`/`includeRoutines`/`includeEvents`/`includeUserTypes` are dead flags.** Plan 14 §3.1/§3.3 emits triggers (with MySQL DELIMITER wrapping), routines, events, user types. Grep shows these four `DumpOptions` fields are never read outside `src/Export/DumpOptions.php`. No such DDL is ever emitted.

5. **MODERATE — `DataStyle::InsertUpdate` and `TruncateInsert` not implemented.** `SqlFormatWriter::writeRows()` only checks `dataStyle === DataStyle::None`, then always renders a plain multi-row `INSERT`. No `ON DUPLICATE KEY` / `INSERT OR REPLACE` / `MERGE` (plan §3.2), no `TRUNCATE` before inserts. Setting either option silently yields plain INSERTs.

6. **MODERATE — Database-section DDL never emitted; `DatabaseSectionStyle` dead.** Plan 14 §2.5 step 2 / §3.1 emit `USE`/`CREATE DATABASE` per `databaseStyle`. `Exporter::export()` never reads `$options->databaseStyle`; `AllDatabases`/`Database` scopes just iterate tables with no database-section output.

7. **MODERATE — PgSQL FK deferral, topological ordering, and `ExportWarningEvent` missing.** Plan 14 §2.5 defers FK `ALTER TABLE`s after all `CREATE TABLE`s for PgSQL, topologically sorts other engines, and emits `ExportWarningEvent` on cycles. `Exporter` iterates `getTables()` in source order; no `ExportWarningEvent.php` exists in `src/Events/`.

8. **MODERATE — Views not properly exported.** Plan 14 §2.5 step 4 dumps views (after tables) as `CREATE VIEW`. `Exporter` doesn't separate views, and `ExportSource::getTableDdl()` always builds `CREATE TABLE` even when `TableStatus::isView` is true — while `SqlFormatWriter::writeTableHeader()` emits `DROP VIEW IF EXISTS`, producing a malformed `DROP VIEW … CREATE TABLE` pair.

9. **MODERATE — `FormatRegistry` + `FormatReaderInterface` missing.** Plan 14 §7's extension registry (`registerWriter`/`registerReader`) and reader-side interface don't exist. Custom writers are only injectable via the `Exporter` constructor; there is no reader abstraction at all. (Cross-ref: [07](07-security-events-plugins-api.md) plugin seams.)

10. **MODERATE — `MultiFileSink` missing.** Plan 14 §2.7's one-file-per-table sink for multi-table CSV export is absent; multi-table CSV concatenates every table into one stream with no separation.

11. **MODERATE — `statementTimeoutMs` is a dead option.** Plan 14 §8.1 requires per-statement timeout via `QueryExecutor::queryWithTimeout()`. The field exists in both `ImportOptions` and `CsvImportOptions` but is never read by `Importer` or `CsvImporter`. (The timeout would be inert anyway — see [05](05-query-engine.md) §10.)

12. **MODERATE — `StatementSplitter` lacks PgSQL dollar-quoting.** Plan 14 §6.2 mandates an `IN_DOLLAR_QUOTE` state for `$tag$…$tag$` / `$$…$$`. `src/Query/StatementSplitter.php` handles single/double/backtick quotes, line/block comments, and DELIMITER directives, but has no dollar-quote state — a `;` inside a PgSQL `$$` function body would split the statement. `StatementSplitterTest` covers the DELIMITER directive but not dollar-quoted bodies.

13. **MINOR — Max-file-size limit / `ImportFileTooLargeException` missing.** Plan 14 §8.1 checks `getEstimatedSize()` against a cap; no `maxFileSize` option or exception exists.

14. **MINOR — Cancellation not wired.** Plan 14 §8.3 specifies `ImportResult::$cancelled` and cancellation via `BeforeQueryExecuted`. `ImportResult` has no `cancelled` field and `Importer` has no cancellation path.

15. **MINOR — CSV import drops unknown columns silently.** Plan 14 §6.4 says unmapped source columns fire a warning event; `CsvImporter::knownColumns()` skips them with no event.

16. **MINOR — CSV line ending not configurable.** Plan 14 §4.1 says `\r\n` default, configurable to `\n`; `AbstractDelimitedFormatWriter::renderRecord()` hardcodes `"\r\n"`.

## 2. Drift

1. **CRITICAL — DDL generation bypasses `DdlBuilder`; lossy schema export.** Plan 14 §2.4/§3.1 + composability goal #5 build `CREATE TABLE` via `DdlBuilder`/`CreateTableBuilder` from the full `TableStatus` (columns, PK, unique, check, indexes, FKs, auto-increment). Actual `ExportSource::getTableDdl()` hand-rolls a minimal `CREATE TABLE` containing only *column name + data-type name + `NOT NULL` + inline per-column `PRIMARY KEY`*. Lost: defaults, auto-increment (renders `includeAutoIncrement` dead), unique/check constraints, indexes, FKs, collation — and composite PKs are mis-rendered (a `PRIMARY KEY` suffix per column). The round-trip test only uses a trivial 3-column table this minimal DDL can reproduce, so the gap is not caught.

2. **MODERATE — Importer splitting reintroduces a chunk-boundary hazard.** Plan 14 §6.2's splitter reads the stream directly so chunk boundaries never affect correctness. `Importer` (`src/Import/Importer.php`) instead accumulates an 8 KB buffer and only calls the splitter when the buffer happens to end in `;` (`endsWithStatementDelimiter()`); a `;` at a chunk boundary inside a quoted string triggers a premature split of a partial statement — the exact bug class the plan criticized Adminer for. The splitter signature also drifted: plan `split(mixed $stream): \Generator` → actual `split(string $sql): StatementBatch`.

3. **MODERATE — CSV upsert falls back silently on PgSQL/MSSQL/Oracle.** Plan 14 §3.2/§6.4 map `InsertOrReplace` to `INSERT OR REPLACE` (PgSQL/SQLite) / `MERGE` (MSSQL/Oracle) with a warning event when unsupported. `CsvImporter::insertPrefix()` only special-cases sqlite/mysql/mariadb; pgsql/mssql/oracle silently get plain `INSERT` for `InsertOrIgnore`/`InsertOrReplace` (no `ON CONFLICT`, no `MERGE`, no warning).

4. **MINOR — `StatementSplitter` relocated.** Plan places `StatementSplitterInterface` in `Contracts\Import`; actual lives in `Contracts\Execution`, implemented in `src/Query/`. Defensible (shared with the query engine) but a namespace deviation. (Cross-ref: [05](05-query-engine.md).)

5. **MINOR — Exporter/TableDumper wiring differs.** Plan wires `SchemaManager` + `DdlBuilderFactory`; actual injects an `ExportSourceInterface` port + `QueryExecutorInterface`, with writers passed variadically into `Exporter`. Transaction wrapping also uses `ConnectionInterface::beginTransaction()` directly rather than the planned `TransactionManager::transactional()` (plan §6.3).

## 3. Extras

1. **`ExportSourceInterface` port + `ExportSource`** (`src/Contracts/Export/ExportSourceInterface.php`, `src/Metadata/ExportSource.php`, marked `@internal`) — a dedicated export-metadata abstraction the plan never mentions (it used `SchemaManager`/inspectors directly).
2. **`Exporter` variadic writer injection** — constructor accepts an optional `ImportExportEventDispatcherInterface` plus variadic `FormatWriterInterface`s and builds an internal format→writer map; an inline substitute for the planned `FormatRegistry`.
3. **CSV import base64-decodes binary** (`CsvImporter::mapRow()`) — plan 14 §4.1 specifies base64 only for export; the import-side decode is an unlisted but consistent addition.
4. **Bounded-memory import is test-enforced** — `testLargeSqlImportUsesBoundedMemory()` asserts < 16 MB peak for 20,000 statements, a concrete enforcement of plan goal #2 / §8.1 (evidence, not code).

## 4. Faithful to Plan

- **SQL/CSV/CSV-semicolon/TSV export writers** with the `DumpOptions`/`DumpScope`/enum VOs per plan 14 §2–§5 (modulo the dead flags above).
- **SQL + CSV import pipelines** (`Importer`, `CsvImporter`, `CsvImportOptions`) per plan 14 §6.
- **PSR-14 progress events** (all seven import/export events) emitted during both pipelines — per plan 14 §8.2 and plan 16.
- **Round-trip + bounded-memory integration tests** per plan 14's testing goals.
- **Sinks** (`ResourceSink`, `StringBufferSink`) implement `SinkInterface` per plan 14 §2.1 — the two uncompressed sinks.

## 5. Summary

The implemented core is a working but substantially narrowed subset of plan 14: SQL/CSV/TSV export, SQL + CSV import, the options/enum VOs, PSR-14 progress events, and round-trip + bounded-memory tests all land as designed. However, two planned subsystems are entirely absent — built-in import sources and all compression (gzip/bzip2 export sinks + import decompression) — and the DDL export path drifts critically by hand-rolling a lossy `CREATE TABLE` instead of reusing `DdlBuilder`, which silently disables auto-increment, constraints, indexes, FKs, triggers/routines/events, database-section DDL, view handling, and PgSQL FK ordering (their `DumpOptions` flags are dead). Secondary drifts include the chunk-boundary-sensitive import buffering, missing dollar-quote support in the splitter, an unused `statementTimeoutMs`, and silent CSV-upsert fallback on PgSQL/MSSQL/Oracle.
