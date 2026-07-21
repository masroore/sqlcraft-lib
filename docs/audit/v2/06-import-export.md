# Audit 06 — Import / Export

**Auditor:** automated read-only audit
**Date:** 2026-07-21
**Scope:** `src/Import/`, `src/Export/`, `src/Contracts/Import/`, `src/Contracts/Export/`, `src/Events/` (import/export subset), `tests/Unit/Import`, `tests/Unit/Export`, `tests/Integration/ImportExport`
**Reference docs:** `docs/plans/14-import-export.md`, `docs/plans/07-module-breakdown.md §10`, `docs/plans/04-feature-inventory.md §16–17`, `docs/plans/16-events.md §5.5`
**Status:** READ-ONLY — no source files were modified

---

## Summary table

| # | Area | Severity | Promise | Reality |
|---|------|----------|---------|---------|
| 1 | Importer stream architecture | HIGH | State-machine splitter reads from `resource $stream`, O(1) memory | Crude chunk accumulation + `endsWithStatementDelimiter()` check |
| 2 | `includeTriggers/Routines/Events/UserTypes` flags | HIGH | Exported when flag is `true` (capability-gated) | Flags declared in `DumpOptions` but never read by any export code |
| 3 | `AllDatabases` scope silently broken | HIGH | Outer loop over all databases | Routed to same single-DB path as `Database`; no multi-DB iteration |
| 4 | `statementTimeoutMs` not consumed | MEDIUM | Per-statement timeout via `queryWithTimeout()` | Field exists in `ImportOptions`; never read by `Importer` |
| 5 | Compression sinks absent | MEDIUM | `GzipSink`, `Bzip2Sink` promised | Neither class exists anywhere in `src/` |
| 6 | `Psr7StreamSink` / `MultiFileSink` absent | MEDIUM | Promised in plan §2.1 | Neither class exists |
| 7 | Import source classes absent | MEDIUM | `FileImportSource`, `StreamImportSource`, `StringImportSource`, `Psr7StreamImportSource` | None exist; integration tests use anonymous classes as workarounds |
| 8 | `FormatRegistry` absent | MEDIUM | Typed writer/reader registry with `registerWriter()`, `getSupportedWriteFormats()` | No registry; `Exporter` takes writers as constructor variadics; no `FormatReaderInterface` |
| 9 | `TopologicalTableSorter` / `ExportWarningEvent` absent | MEDIUM | FK-cycle detection + warning event; PgSQL FK deferral | Neither exists; `Exporter` does no table ordering |
| 10 | CSV upsert silent fallback on pgsql/mssql/oracle | MEDIUM | `InsertOrIgnore/Replace` platform-mapped | `insertPrefix()` covers only sqlite and mysql/mariadb; other engines silently return plain `INSERT` |
| 11 | `ImportProgressEvent` not fired by `CsvImporter` | LOW | All import events fired (doc 16 §5.5) | `CsvImporter` fires `importStarted`/`importFinished`/`importFailed` only; `importProgress` never called |
| 12 | `JsonFormatWriter` / `XmlFormatWriter` absent — deferral undocumented | LOW | Plan §5 describes both in full detail | Neither exists; no explicit deferral notice in any plan or roadmap doc |
| 13 | `FormatReaderInterface` absent | LOW | Described in plan §7 as reader-side counterpart | No interface in `Contracts/Import/` or anywhere else |
| 14 | TSV is CSV with tab — no independent behaviour | LOW | Expected (plan §4.1 confirms this) | `TsvFormatWriter` correctly inherits `AbstractDelimitedFormatWriter`; but `CsvFormatWriter` + `csvSeparator: "\t"` produces identical output with no guard |
| 15 | Integration test coverage gaps | LOW | Large-file / round-trip tests expected | SQL and CSV round-trips exist (sqlite only for CSV); TSV, multi-table, AllDatabases, FilteredResult, pgsql FK ordering, gzip absent |

---

## Finding 1 — Importer stream architecture diverges from plan (HIGH)

**Promise:** doc 14 §6.2 — `StatementSplitterInterface::split(resource $stream, string $delimiter): \Generator` reads the stream directly, emits one complete statement per `yield`, is O(1) memory, and eliminates chunk-boundary edge cases.

**Reality — StatementSplitter signature mismatch:**
`/Users/masroor/projects/adminer-ng/sqlcraft/src/Query/StatementSplitter.php`
```
public function split(string $sql, string $delimiter = ';'): StatementBatch
```
Takes a `string`, returns a `StatementBatch` (fully-materialized array of statements). Not a stream, not a Generator.

**Reality — Importer does its own crude chunk accumulation:**
`/Users/masroor/projects/adminer-ng/sqlcraft/src/Import/Importer.php` lines 55–83 read 8 KB chunks into a `$buffer` string and only call `$this->splitter->split($buffer)` when `endsWithStatementDelimiter($buffer)` returns true. That helper is:
```php
private function endsWithStatementDelimiter(string $sql): bool
{
    return str_ends_with(rtrim($sql), ';');
}
```
This replicates the exact class of bug the plan promises to fix: a statement whose body contains a string literal ending in `;` (e.g., `INSERT … VALUES ('abc;')`) will cause the buffer to be flushed prematurely and the splitter to see a broken fragment. Additionally, a very large statement (e.g., a long `CREATE TABLE`) causes the buffer to grow without bound until a top-level `;` appears — the O(1) memory guarantee does not hold.

**Fix:** Align the `StatementSplitterInterface` (in `src/Contracts/Execution/`) to accept `resource $stream` and return `\Generator<string>`. Rewrite `StatementSplitter` accordingly. Remove the chunk-accumulation loop from `Importer` and replace it with `foreach ($this->splitter->split($stream, ';') as $statement)`.

---

## Finding 2 — `includeTriggers/Routines/Events/UserTypes` flags are dead (HIGH)

**Promise:** doc 04 §17 — "Include triggers/routines/events in dump — Export honors the same capability gates"; doc 14 §3.1 — trigger emission with DELIMITER wrapping; doc 14 §2.3 — `DumpOptions::$includeTriggers/includeRoutines/includeEvents/includeUserTypes`.

**Reality:** All four flags are declared in `DumpOptions` but a search across `TableDumper.php`, `SqlFormatWriter.php`, and `Exporter.php` finds no reference to any of them. No trigger, routine, event, or user-type DDL is ever emitted regardless of flag values.

Files affected:
- `/Users/masroor/projects/adminer-ng/sqlcraft/src/Export/DumpOptions.php` (declarations present)
- `/Users/masroor/projects/adminer-ng/sqlcraft/src/Export/TableDumper.php` (no reads — gap)
- `/Users/masroor/projects/adminer-ng/sqlcraft/src/Export/SqlFormatWriter.php` (no writes — gap)

**Fix:** In `TableDumper::dump()`, after `writeTableFooter()`, check `$options->includeTriggers` and call a `getTriggerDdl()` method on `ExportSourceInterface`; similarly wire `includeRoutines`/`includeEvents` at the database level in `Exporter::exportDatabase()`. Each section should be guarded by a capability check (`$conn->supports(Capability::Triggers)` etc.) as described in plan §3.1.

---

## Finding 3 — `AllDatabases` scope silently falls through to single-DB path (HIGH)

**Promise:** doc 14 §2.6 — `AllDatabases` scope triggers `ServerInspectorInterface::getDatabases()` then `getTables()` per database, with database-section DDL emitted between each.

**Reality:** `Exporter::export()` line 55:
```php
ScopeKind::Database, ScopeKind::AllDatabases => $this->exportDatabase(…),
```
`exportDatabase()` calls `$this->source->getTables($conn)` once for the current connection. There is no outer database loop, no `getDatabases()` call, and no `ServerInspectorInterface` usage anywhere in the export pipeline. A consumer requesting `DumpScope::allDatabases()` receives a dump of only the connection's active database.

**Fix:** Split the `match` arm — `AllDatabases` should call a new `exportAllDatabases()` method that iterates `ServerInspectorInterface::getDatabases()` and calls `exportDatabase()` per database, emitting `DatabaseSectionStyle` DDL between each.

---

## Finding 4 — `statementTimeoutMs` declared but never consumed (MEDIUM)

**Promise:** doc 14 §8.1 — "Per-statement timeout: `ImportOptions::$statementTimeoutMs`; each statement wrapped in `QueryExecutor::queryWithTimeout()`".

**Reality:** `ImportOptions` field exists and is validated (`>= 0`). `Importer::executeSql()` passes statements to `BatchExecutor::executeBatch()` with no timeout parameter. `statementTimeoutMs` is never read.

Files:
- `/Users/masroor/projects/adminer-ng/sqlcraft/src/Import/ImportOptions.php` (declared)
- `/Users/masroor/projects/adminer-ng/sqlcraft/src/Import/Importer.php` (never read — gap)

**Fix:** Thread `$options->statementTimeoutMs` into `BatchExecutorInterface::executeBatch()` (add optional param) or call `QueryExecutor::queryWithTimeout()` directly per statement when the value is non-zero.

---

## Finding 5 — Compression sinks `GzipSink` and `Bzip2Sink` absent (MEDIUM)

**Promise:** doc 14 §2.1 — `GzipSink` decorates a `SinkInterface` using `ext-zlib`; `Bzip2Sink` uses `ext-bz2`; both throw `ExtensionMissingException` at construction if the extension is unavailable; never silently fall back to uncompressed output. doc 04 §17: `Capability::GzipCompression`, `Capability::Bzip2Compression`.

**Reality:** Neither class exists anywhere under `src/`. Gzip and bzip2 output are completely unimplemented. There is no `ExtensionMissingException` either (not searched exhaustively but not found in the export path).

**Fix:** Implement `GzipSink` and `Bzip2Sink` as `SinkInterface` decorators. `GzipSink` should open a `zlib.deflate` stream filter on construction; `Bzip2Sink` similarly with `bzip2.compress`. Both must check extension availability immediately at construction.

---

## Finding 6 — `Psr7StreamSink` and `MultiFileSink` absent (MEDIUM)

**Promise:** doc 14 §2.1 — `Psr7StreamSink` wraps a PSR-7 `StreamInterface`; `MultiFileSink` routes each table's bytes to a separate `SinkInterface` (enabling CSV-per-table exports).

**Reality:** Neither class exists. The only concrete sinks are `ResourceSink` and `StringBufferSink`.

**Fix:** Implement both. `Psr7StreamSink` is a thin adapter (PSR-7 `StreamInterface::write` → `SinkInterface::write`). `MultiFileSink` requires a naming callback and a factory that returns a fresh `SinkInterface` per table name.

---

## Finding 7 — Built-in import source classes absent (MEDIUM)

**Promise:** doc 14 §6.1 — four concrete `ImportSourceInterface` implementations: `FileImportSource` (with gzip magic-byte detection), `StreamImportSource`, `StringImportSource`, `Psr7StreamImportSource`.

**Reality:** `src/Import/` contains only: `CsvImporter`, `CsvImportOptions`, `Importer`, `ImportError`, `ImportOptions`, `ImportResult`, `UpsertMode`. No source classes exist. The integration test works around this by defining anonymous classes inline.

**Fix:** Implement all four. `FileImportSource` is the most critical — it must detect `.gz` magic bytes (`\x1f\x8b`) and apply a `zlib.inflate` stream filter before returning, throwing `ExtensionMissingException` if `ext-zlib` is unavailable.

---

## Finding 8 — `FormatRegistry` absent; `FormatReaderInterface` absent (MEDIUM)

**Promise:** doc 14 §7 — `FormatRegistry` with `registerWriter(FormatWriterInterface)`, `registerReader(FormatReaderInterface)`, `getWriter(string $format)`, `getSupportedWriteFormats()`. Built-in formats pre-registered. `FormatReaderInterface` as reader-side counterpart.

**Reality:** No `FormatRegistry` class. `Exporter` constructor accepts writers as a variadic parameter (`FormatWriterInterface ...$writers`) and builds a private map. This makes it impossible for consumers to query supported formats, add a custom writer without reconstructing `Exporter`, or discover what formats are registered. `FormatReaderInterface` does not exist in `Contracts/Import/` or anywhere else.

**Fix:** Implement `FormatRegistry` in `src/Export/`; change `Exporter` constructor to accept `FormatRegistry` instead of or in addition to the variadic. Define `FormatReaderInterface` in `Contracts/Import/`.

---

## Finding 9 — `TopologicalTableSorter` and `ExportWarningEvent` absent (MEDIUM)

**Promise:** doc 14 §2.5 — FK-dependency topological sort; cycle detection falls back to declaration order and emits `ExportWarningEvent`; PgSQL defers FK `ALTER TABLE` statements after all `CREATE TABLE` statements.

**Reality:** No `TopologicalTableSorter`, no `ExportWarningEvent`. `Exporter::exportDatabase()` iterates tables in whatever order `ExportSourceInterface::getTables()` returns them. No FK ordering is applied. No PgSQL FK deferral logic exists. `ExportWarningEvent` is not in `src/Events/`.

**Fix:** Implement `TopologicalTableSorter` (a simple Kahn's algorithm over FK metadata); add `ExportWarningEvent`; add PgSQL-specific FK deferral path in `Exporter::exportDatabase()`.

---

## Finding 10 — CSV upsert silently falls back on pgsql / mssql / oracle (MEDIUM)

**Promise:** doc 14 §6.4 — `UpsertMode::InsertOrIgnore / InsertOrReplace` are "platform-mapped"; the plan implies all supported platforms are covered.

**Reality:** `CsvImporter::insertPrefix()` handles only `sqlite` and `mysql`/`mariadb`. For all other platforms (pgsql, mssql, sqlserver, oracle) the method returns plain `'INSERT'` regardless of the requested `UpsertMode`. No warning event is fired; the caller has no indication the upsert mode was silently ignored.

**Fix:** Add pgsql handling (`INSERT … ON CONFLICT DO NOTHING` / `ON CONFLICT DO UPDATE SET …`). For mssql use `MERGE`. For oracle use `MERGE`. If a platform is genuinely unsupported, fire a `CapabilityNotSupportedEvent` rather than silently downgrading.

---

## Finding 11 — `ImportProgressEvent` not fired by `CsvImporter` (LOW)

**Promise:** doc 16 §5.5 — `ImportProgressEvent` listed with payload `$bytesProcessed`, `$statementsExecuted`, `$elapsedMs`; doc 14 §6.3 — fired every `$progressInterval` statements.

**Reality:** `CsvImporter::importCsv()` calls `importStarted`, `importFinished`, and `importFailed` on the dispatcher. It never calls `importProgress` after batches, making progress monitoring impossible for CSV imports.

**Fix:** Add `$this->events?->importProgress(...)` after each batch execution inside `CsvImporter::importCsv()`, matching the interval logic used in `Importer::executeSql()`.

---

## Finding 12 — `JsonFormatWriter` / `XmlFormatWriter` absent with no deferral notice (LOW)

**Promise:** doc 14 §5 describes `JsonFormatWriter` and `XmlFormatWriter` in full streaming detail (structure, NULL encoding, binary encoding, row-as-array vs row-as-object option).

**Reality:** Neither class exists. There is no mention in any plan or roadmap file marking them as deferred, out-of-scope for M7, or post-MVP. The contrast table in doc 14 §9 states the built-in set as "SQL, CSV, TSV" — inconsistent with §5's detailed design.

**Fix:** Either implement both (they are fully specified) or add an explicit `> **Status: Deferred to M8**` notice in doc 14 §5 and a line in the roadmap. The absence of deferral documentation creates an implied commitment.

---

## Finding 13 — `FormatReaderInterface` absent (LOW)

**Promise:** doc 14 §7 — `FormatReaderInterface::readRows(resource $stream, FormatReadOptions $options): \Generator` as the reader-side counterpart to `FormatWriterInterface`.

**Reality:** No interface, no `FormatReadOptions` class, nothing in `Contracts/Import/` beyond `CsvImporterInterface`, `ImporterInterface`, and `ImportSourceInterface`.

**Fix:** Define `FormatReaderInterface` and `FormatReadOptions` in `Contracts/Import/`. The `CsvImporter` can then be refactored to implement it or use an injected reader.

---

## Finding 14 — TSV is identical to CSV with tab; no deduplication guard (LOW)

**Promise/design:** doc 14 §4.1 confirms this is by design ("Same pipeline as CSV with tab delimiter preset"). Not a bug in `TsvFormatWriter` itself.

**Observation:** `TsvFormatWriter` correctly extends `AbstractDelimitedFormatWriter` with `defaultSeparator(): "\t"`. However, `CsvFormatWriter` with `DumpOptions::$csvSeparator = "\t"` produces byte-for-byte identical output to `TsvFormatWriter` with default options. There is no guard in `AbstractDelimitedFormatWriter` to warn when the runtime separator matches another format's default, and the `getFormatName()` distinction has no effect on output bytes.

**Fix (low priority):** Add a constructor-time assertion in `TsvFormatWriter` that `$options->csvSeparator` is `null` or `"\t"` when format is `tsv`; or document that `csvSeparator` overrides apply to all delimited formats.

---

## Finding 15 — Integration test coverage gaps (LOW)

**Present tests** (`tests/Integration/ImportExport/ImportExportRoundTripTest.php`):
- SQL round-trip: sqlite (runs in CI), mysql/mariadb/pgsql (guarded by `SQLCRAFT_RUN_ENGINE_INTEGRATION=1`)
- Large SQL import memory bound: sqlite, 20K rows, 16 MB peak delta assertion
- CSV round-trip (null + binary): sqlite only

**Absent coverage:**
- TSV export/import round-trip (format exists but untested end-to-end)
- Multi-table export (only single-table scope tested)
- `AllDatabases` scope (finding 3 makes this moot until fixed)
- `FilteredResult` scope (`DumpScope::filteredResult()` exists, zero test coverage)
- pgsql FK ordering / deferral (finding 9; also no pgsql-specific export test)
- Gzip round-trip (blocked by finding 5)
- `CsvSemicolonFormatWriter` (no test at any level)
- `UpsertMode::InsertOrIgnore` / `InsertOrReplace` on any engine

**Fix:** Once blockers 1, 5, 7 are resolved, add: `testTsvRoundTrip`, `testFilteredResultExport`, `testCsvUpsertOnSqlite`, and a pgsql-gated `testPgsqlFkDeferral`.

---

## Dead / orphan seams

| Item | Location | Status |
|------|----------|--------|
| `DumpOptions::$includeTriggers` | `src/Export/DumpOptions.php:18` | Declared, never read (finding 2) |
| `DumpOptions::$includeRoutines` | `src/Export/DumpOptions.php:19` | Declared, never read (finding 2) |
| `DumpOptions::$includeEvents` | `src/Export/DumpOptions.php:20` | Declared, never read (finding 2) |
| `DumpOptions::$includeUserTypes` | `src/Export/DumpOptions.php:21` | Declared, never read (finding 2) |
| `ImportOptions::$statementTimeoutMs` | `src/Import/ImportOptions.php:15` | Declared, never consumed (finding 4) |
| `CsvImportOptions::$statementTimeoutMs` | `src/Import/CsvImportOptions.php:17` | Declared, never consumed (same gap in CsvImporter) |
| `CsvSemicolonFormatWriter` | `src/Export/CsvSemicolonFormatWriter.php` | Implemented, never registered (no FormatRegistry — finding 8) |

---

## Event wiring summary

| Event class | Defined | Emitted from | Wired correctly |
|-------------|---------|-------------|----------------|
| `ImportStartedEvent` | Yes | `Importer`, `CsvImporter` | Yes |
| `ImportProgressEvent` | Yes | `Importer` only | Partial — CsvImporter gap (finding 11) |
| `ImportFinishedEvent` | Yes | `Importer`, `CsvImporter` | Yes |
| `ImportFailedEvent` | Yes | `Importer`, `CsvImporter` | Yes |
| `ExportStartedEvent` | Yes | `Exporter` | Yes |
| `ExportProgressEvent` | Yes | `Exporter` (per-table) | Yes |
| `ExportFinishedEvent` | Yes | `Exporter` | Yes |
| `ExportWarningEvent` | No | — | Absent (finding 9) |

All seven defined import/export events are properly dispatched through `ImportExportEventDispatcher`, which wraps a PSR-14 `EventDispatcherInterface`. The dispatcher is correctly optional (null-safe calls throughout). The only wiring gap is `ImportProgressEvent` not being emitted by `CsvImporter` and the absent `ExportWarningEvent`.
