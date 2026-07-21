# Phase 5 — Query completeness

> Depends on: Phase 2 (`QueryBuilderInterface`), Phase 3 (`DatabaseSession::query()`).
> Release-blocking: yes for DML — falsifies the M6 "green" claim.
> Closes audit findings: 05 §2 (DML), §3 (FK nav / cross-table), §4 (BLOB), §6 (operator allowlist); 03 §4.1 (cache impls); 07 §6.3 (import cap — cross-ref).

`src/Query/` is SELECT-only. Writing rows requires hand-rolled raw SQL — the exact thing the
library exists to prevent — yet M6 "Query Engine" is green. This phase builds the write-side
builders and the navigation/search features promised as baseline Query capabilities.

---

## 5.1 INSERT / UPDATE / DELETE builders

**Problem:** doc 04 §14 lists row insert/update/delete/clone as baseline (un-gated) Query
features; doc 07 §9 says the Query module provides SELECT/INSERT/UPDATE/DELETE. Only the SELECT
surface exists. (Audit 05 §2, High.)

**Work:**
1. `src/Query/InsertQuery.php` + `InsertQueryRenderer.php` — columns/values, multi-row, `INSERT ... SELECT`, platform-aware upsert delegation (share the upsert-prefix logic with `CsvImporter`, Phase 6 §6.x, so both use one mapping).
2. `src/Query/UpdateQuery.php` + `UpdateQueryRenderer.php` — SET map, WHERE via existing `WhereCondition`, bound params only.
3. `src/Query/DeleteQuery.php` + `DeleteQueryRenderer.php` — WHERE, optional LIMIT where supported.
4. Row clone/duplicate as a thin `InsertQuery::fromRow()` helper.
5. All values bound as `?` params — never interpolated (match `SelectQueryRenderer`'s existing discipline). Identifiers via `platform->quoteIdentifier()`.
6. Expose through `DatabaseSession::query()` and `QueryBuilderInterface`.

**Acceptance:** insert/update/delete a row through the typed builder with bound params, on
SQLite + one server engine; renderers produce platform-correct SQL; a test asserts no value is
string-interpolated. Upsert mapping shared with import (no divergent second copy).

---

## 5.2 FK navigation + cross-table search

**Problem:** doc 04 §14 lists FK navigation ("follow FK to related row"), backward keys
("referenced by"), and cross-table search as baseline Query features. `BackwardKeyMeta` DTO
exists but no service uses it; no FK navigator, no cross-table search anywhere. (Audit 05 §3,
Medium.)

**Work:**
1. `src/Query/FkNavigator.php` — given a `ForeignKeyMeta` + a row's FK value, produce a `SelectQuery` scoped to the referenced row (forward) and, using `BackwardKeyMeta`, the "referenced by" queries (backward).
2. `src/Query/CrossTableSearchService.php` (or `TableSearchService` per doc 15 — unify with Phase 4 §4.6) — fan-out per-table `LIKE` queries over the metadata layer, gated behind `Capability::CrossTableSearch`, with the per-table `$rowCap` (default 1,000) wired from the start.

**Acceptance:** navigate an FK to the parent row and enumerate children via a backward key;
cross-table search returns capped per-table hits. Tests for both directions + the cap.

---

## 5.3 BLOB streaming

**Problem:** doc 04 §14 promises BLOB download exposed as a PHP `resource` stream, never an
in-memory string (per the vision's streaming goal), gated on `Capability::BlobStreaming`. No
BLOB code exists. (Audit 05 §4, Medium.)

**Work:**
1. `src/Query/BlobStreamService.php` (or a `QueryManager` method) — single-column SELECT with an unbuffered cursor, yielding the BLOB as a PHP stream resource, gated on `Capability::BlobStreaming`.

**Acceptance:** downloading a BLOB column yields a `resource`, and peak memory stays bounded for
a large value (memory-delta assertion like the import large-file test). Capability-gated.

---

## 5.4 Operator allowlist fix

**Problem:** `WhereCondition` validates operators against a **hardcoded static list** at
construction, which blocks platform-specific operators (PostgreSQL `~`, `ILIKE`) and accepts
`REGEXP` on engines that lack it. Injection risk is none (render-time platform check is the hard
gate), but it's architecturally wrong and breaks extensibility. (Audit 05 §6, Medium.)

**Work:**
1. Remove the static list from `WhereCondition`; rely on the render-time
`SelectQueryRenderer` check against `platform->getOperators()` (already present). Or inject
`PlatformInterface` into `WhereCondition` for platform-aware construction-time validation, as the
design originally intended. Prefer the former (smaller, single source of truth).

**Acceptance:** a PostgreSQL-specific operator passes on PostgreSQL and is rejected on engines
that lack it, both enforced at render against the real platform operator list. Test per engine.

---

## 5.5 Metadata cache implementations

**Problem:** `MetadataCacheInterface` has four planned impls; only `NullMetadataCache` exists.
`InMemoryMetadataCache`, `Psr6MetadataCache`, `Psr16MetadataCache` are absent, blocking
production cache adoption. (Audit 03 §4.1, Medium.) The invalidation *listener* is Phase 1 §1.3;
this is the *storage* side.

**Work:**
1. `src/Schema/InMemoryMetadataCache.php` (array-backed, TTL-aware).
2. `src/Schema/Psr6MetadataCache.php` (PSR-6 pool adapter).
3. `src/Schema/Psr16MetadataCache.php` (PSR-16 simple-cache adapter).
4. `SQLCraftFactory` lets a consumer choose the cache; default stays `NullMetadataCache`.

**Acceptance:** with `InMemoryMetadataCache`, a repeated `getTable()` issues one query then
serves cached until a DDL event invalidates it (verifies Phase 1 §1.3 end to end). Unit tests per
adapter.

---

## 5.6 Import statement cap default (cross-ref, single line)

`ImportOptions::$maxStatements` defaults to `null` (unbounded). `BatchExecutor`'s 1,000 cap only
guards programmatic batches, not the import pipeline. (Audit 07 §6.3, Medium.) Set a finite safe
default (e.g. 10,000) or document `null` as deliberate-unlimited. Also thread
`statementTimeoutMs` (Audit 06 finding 4) — folded into Phase 6.

**Acceptance:** a default-constructed `ImportOptions` enforces a finite statement cap, or the
null is explicitly documented as caller-owned.

---

## Phase 5 exit criteria

- INSERT/UPDATE/DELETE/clone builders work with bound params on SQLite + a server engine.
- FK navigation (forward + backward) and capped cross-table search work.
- BLOB download streams as a resource with bounded memory.
- Operator validation has a single platform-aware source of truth.
- Three real cache adapters exist; invalidation verified end to end with Phase 1.
- Import statement cap has a safe default.
- `make build`/`make test` green; **M6 "Query Engine" is now actually true.**
