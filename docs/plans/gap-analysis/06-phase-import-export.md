# Phase 6 — Import/Export completeness

> Depends on: Phase 2 (contracts), Phase 3 (`FormatRegistry`).
> Release-blocking: partial — the two correctness bugs (dead flags, AllDatabases) are in Phase 1; this phase is the completeness remainder.
> Closes audit findings: 06 findings 4/5/6/7/9/10/11/12/13; 07 §6.3 (import timeout/cap).

Phase 1 already fixed the streaming splitter (1.6) and the two dead-behavior export bugs
(1.7). This phase builds the missing sinks, sources, format machinery, and FK-aware ordering
that make import/export production-complete. Oracle branches are omitted throughout.

---

## 6.1 Import sources

**Problem:** doc 14 §6.1 promises four `ImportSourceInterface` impls; none exist. Integration
tests use inline anonymous classes as workarounds. (Audit 06 finding 7, Medium.)

**Work:** `src/Import/FileImportSource.php` (detects `.gz` magic bytes `\x1f\x8b`, applies
`zlib.inflate` filter, throws `ExtensionMissingException` if `ext-zlib` absent),
`StreamImportSource.php`, `StringImportSource.php`, `Psr7StreamImportSource.php`.

**Acceptance:** each source feeds the streaming splitter (Phase 1 §1.6); `FileImportSource`
transparently handles a gzipped `.sql.gz`. Tests replace the anonymous-class workarounds.

---

## 6.2 Export sinks

**Problem:** doc 14 §2.1 promises `GzipSink`, `Bzip2Sink`, `Psr7StreamSink`, `MultiFileSink`.
Only `ResourceSink` + `StringBufferSink` exist. (Audit 06 findings 5, 6, Medium.)

**Work:**
1. `src/Export/GzipSink.php` (zlib.deflate filter; check `ext-zlib` at construction), `Bzip2Sink.php` (bzip2.compress; check `ext-bz2`). Both throw `ExtensionMissingException` at construction, never silently fall back to uncompressed.
2. `src/Export/Psr7StreamSink.php` (PSR-7 `StreamInterface::write` adapter).
3. `src/Export/MultiFileSink.php` (naming callback + per-table sink factory, enabling CSV-per-table).
4. Add `ExtensionMissingException` if absent. Capabilities `GzipCompression`/`Bzip2Compression` per doc 04 §17.

**Acceptance:** gzip round-trip (export→GzipSink→FileImportSource) reproduces the source;
missing extension throws at construction. Tests gated on extension availability.

---

## 6.3 `FormatReaderInterface` + reader side

**Problem:** doc 14 §7 promises `FormatReaderInterface::readRows(resource, FormatReadOptions):
\Generator` as the reader counterpart. Absent. (Audit 06 finding 13, Low.)

**Work:** `src/Contracts/Import/FormatReaderInterface.php` + `FormatReadOptions`; register readers
in `FormatRegistry` (Phase 3 §3.5). Refactor `CsvImporter` to consume an injected reader.

**Acceptance:** CSV import goes through a registered `FormatReaderInterface`; registry reports
supported read formats.

---

## 6.4 Topological table ordering + FK deferral

**Problem:** doc 14 §2.5 promises FK-dependency topological sort with cycle detection →
`ExportWarningEvent`, and PgSQL FK-`ALTER TABLE` deferral after all `CREATE TABLE`. None exist;
`Exporter` dumps tables in metadata order. (Audit 06 finding 9, Medium.)

**Work:**
1. `src/Export/TopologicalTableSorter.php` — Kahn's algorithm over FK metadata; on cycle, fall back to declaration order and emit `ExportWarningEvent`.
2. `src/Events/ExportWarningEvent.php` (the one missing import/export event).
3. PgSQL path: emit FK constraints as deferred `ALTER TABLE ADD CONSTRAINT` after all `CREATE TABLE`.

**Acceptance:** a schema with inter-table FKs dumps in dependency order; a cycle produces an
`ExportWarningEvent` and still completes; PgSQL dump restores cleanly with FKs. Tests + a
pgsql-gated FK-deferral test.

---

## 6.5 CSV upsert platform mapping

**Problem:** `CsvImporter::insertPrefix()` handles only sqlite + mysql/mariadb; pgsql/mssql
silently return plain `INSERT`, ignoring the requested `UpsertMode` with no warning. (Audit 06
finding 10, Medium.)

**Work:** add PgSQL (`INSERT ... ON CONFLICT DO NOTHING` / `DO UPDATE SET`), MSSQL (`MERGE`).
**No Oracle.** If a supported platform genuinely can't express the mode, fire
`CapabilityNotSupportedEvent` rather than silently downgrading. Share this mapping with
`InsertQuery` upsert (Phase 5 §5.1) — one source of truth.

**Acceptance:** `InsertOrIgnore`/`InsertOrReplace` produce correct engine SQL on
sqlite/mysql/pgsql/mssql; unsupported combos fire the capability event. Tests per engine.

---

## 6.6 Import timeout + progress + smaller gaps

- **`statementTimeoutMs`** (Audit 06 finding 4): thread `ImportOptions::$statementTimeoutMs` into per-statement execution via `QueryExecutor::queryWithTimeout()` when non-zero.
- **`ImportProgressEvent` from `CsvImporter`** (Audit 06 finding 11): fire `importProgress` after each batch, matching `Importer`'s interval logic.
- **`JsonFormatWriter`/`XmlFormatWriter`** (Audit 06 finding 12): fully specified in doc 14 §5 but absent with no deferral note. **Decision:** defer to a future version — add an explicit `> Status: Deferred` note in doc 14 §5 and the roadmap (Phase 8). Do not leave the implied commitment dangling. (Build only if a consumer needs them for v1.0.)
- **TSV/CSV separator guard** (Audit 06 finding 14, Low): add a constructor assertion or document that `csvSeparator` overrides apply to all delimited formats.

**Acceptance:** import honors per-statement timeout; CSV import emits progress events;
JSON/XML writers carry an explicit deferral note (not silent absence).

---

## 6.7 Integration coverage

Once 6.1/6.2/6.5 land, add the tests audit 06 finding 15 lists as absent: TSV round-trip,
`FilteredResult` scope, multi-table export, CSV upsert on sqlite, pgsql FK deferral (gated),
gzip round-trip.

---

## Phase 6 exit criteria

- Four import sources + four export sinks exist; gzip round-trip works; missing extensions throw at construction.
- Reader side (`FormatReaderInterface`) registered in `FormatRegistry`.
- Topological FK ordering + `ExportWarningEvent` + PgSQL FK deferral work.
- CSV/INSERT upsert mapping covers sqlite/mysql/pgsql/mssql (no Oracle), shared with Phase 5.
- Import timeout + CSV progress events wired; JSON/XML explicitly deferred.
- Integration coverage gaps filled.
- `make build`/`make test` green.
