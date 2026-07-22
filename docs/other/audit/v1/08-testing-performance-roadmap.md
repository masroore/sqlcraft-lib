# 08 — Testing, Performance, Roadmap & Open Questions Audit

> **Status:** Final
> **Audit date:** 2026-07-21
> **Baseline:** `master @ 6d50506`
> **Plans reviewed:** `20-testing.md`, `21-performance.md`, `22-migration-map.md`, `23-roadmap.md`, `24-open-questions.md`, `25-final-review.md`, plus `04-feature-inventory.md`
> **Implementation reviewed:** `tests/` (Unit, Integration, Contract, Fixtures, Golden), `phpunit.xml.dist`, `infection.json.dist`, `composer.json`, `.github/workflows/`, `tools/`, `docs/PROGRESS.md`

---

## 1. Gaps

### Testing (plan 20)

- **CRITICAL — Mutation gate not met.** Plan 20 §6/§12 + M10 acceptance require MSI ≥ 80% / covered MSI ≥ 90%. `composer.json`'s `infection` script enforces `--min-msi=80 --min-covered-msi=90`, but PROGRESS.md M10 T3 is `blocked by Infection MSI 57% / covered MSI 75%`. This is the sole blocker to the v1.0.0 tag. Note: `infection.json.dist` itself carries no `minMsi`/`minCoveredMsi` keys — thresholds live only in the CLI script — and `composer ci` does not run infection at all.

- **MODERATE — Contract tier is a thin subset of plan 20 §4.** `tests/Contract/PlatformConformanceTestCase.php` has only 5 tests (quoted-identifier, 2× pagination, quoted-value, basic capability presence). Plan 20 §4 — calling this "the single most important test asset" — specified describeTable column-order conformance, **full capability-matrix-vs-live-behavior** (attempt every claimed capability), FK-action round-trips, transaction/savepoint conformance, NULL-vs-empty-string `DefaultValue` discrimination, streaming-cursor exhaustion, and prepared-statement rebind. The capability test only asserts `Table`/`Columns`/`Sql` are present, not the per-capability live attempt.

- **MODERATE — Golden tier scope narrowed.** Plan 20 §5 wanted per-(platform × statement-shape) DDL/Query fixtures (`create_table_simple.sql`, `select_with_pagination.sql`, `alter_table_…`). Actual `tests/Golden/` = one `IntrospectionSqlGoldenTest.php` + 4 introspection fixtures (mysql/mariadb/pgsql/sqlite). No DDL/query-generation golden files; no sqlserver introspection fixture.

- **MODERATE — Property-based testing absent.** Plan 20 §7 + M1 acceptance criteria require property tests (quoting round-trips, `DefaultValue` discrimination, `ServerVersion` transitivity). No `*PropertyTest.php` exists and no generator library (e.g., `eris`) is in `composer.json`.

- **MODERATE — Memory/streaming regression test missing.** Plan 20 §9/§10 require a standing `#[Group('memory')]` streamed-vs-eager `memory_get_peak_usage` assertion and a `tests/Fixtures/LargeSchemaGenerator.php`. Only one memory assertion exists (`tests/Integration/ImportExport/ImportExportRoundTripTest.php:141`, large-file import); no `Group('memory')` test, no `LargeSchemaGenerator`.

- **MINOR — Coverage floors not enforced.** Plan 20 §12 sets ≥ 95% (VO/DTO/Collections/Capabilities) / ≥ 90% overall. `phpunit.xml.dist` defines no coverage thresholds; CI uploads to Codecov but no enforced floor is configured.

### Performance (plan 21)

Implemented: streaming (§3 — `StreamingResult`), N+1-safe batch introspection (§7 — `SchemaManager::streamTables()/getAllColumns()`, `getAllColumnsSql/getAllIndexesSql/getAllForeignKeysSql` on all 5 platforms), approximate counts (§6 — `Paginator` + `TableStatusProviderInterface::getApproximateRowCount`). Missing:

- **MODERATE — `LazyCollection` (§2) absent.** All 21 collections extend the eager `AbstractImmutableCollection`; no closure-backed lazy collection. `streamTables()` only partially substitutes.
- **MODERATE — `PreparedStatementCache` LRU reuse (§5) absent.** `PdoConnection::prepare()` constructs a fresh `PdoPreparedStatement` per call; no SQL-keyed LRU. (Cross-ref: [03](03-connection-layer.md) — statements also re-prepare on execute.)
- **MODERATE — Keyset/seek pagination (§6) absent.** No `seekAfter()` anywhere; only LIMIT/OFFSET `Paginator`. No explicit `exactCount()` opt-in either.
- **MODERATE — Memory ceilings/row caps (§8) absent.** No `ResultSetTooLargeException` (not among the 26 exceptions), no `fetchAllCapped()`.
- **MODERATE — Metadata caching (§4) only a seam.** `MetadataCacheInterface` + `NullMetadataCache` + `SchemaManager` wiring exist, but **no concrete cache** (open-Q 4.3's `InMemoryMetadataCache` not shipped) and **no `CacheInvalidatingListener` on `DdlExecutedEvent`** (invalidation methods exist on the interface but nothing calls them — cross-ref [04](04-schema-ddl.md)).
- **MODERATE — Benchmarking (§10) absent.** No `tools/benchmarks/`, no `phpbench` dependency (`tools/` holds only `generate-contract-report.php` and `rector-dry-run.sh`); the §11 5,000-table `describeAllTables()` target is unverified. The named `describeAllTables()` bulk generator itself does not exist (only single-table `describeTable()` + `getAllColumns()`).

### Feature inventory (04) with no milestone/code home

- **MODERATE — Cross-table search** (§14, `Capability::CrossTableSearch`): no service, no code; doc 25 §5 already flagged "no home." Still unresolved. (Cross-ref: [04](04-schema-ddl.md) `TableSearchService`.)
- **MODERATE — Maintenance ops** (§3/§20: Analyze/Optimize/Check/Repair/Vacuum, `Capability::TableAnalyze` etc.): no implementing service in `src/`.
- **MINOR — MySQL event scheduler** (§11, `Capability::Events`): no `EventInspector`/event DDL.

### Final review (25) — unmet items

Doc 25 lists no formal acceptance criteria (it is an adversarial self-review); its actionable items map to: §5 gaps (cross-table search service, governance doc, vendor-specific DTO escape hatch — all still absent), §6 recommended actions (Packagist name check not done; Oracle-CI spike resolved only by deferral), and weakness 2.4 (unbuffered-cursor interleaving guard — `StreamingResultException`/`ConnectionClosedException` exist but a typed guard for second-query-on-open-cursor was not confirmed). The de-facto v1.0 acceptance gate (M10: green CI + infection 80/90 + tag) is **unmet** because of the MSI shortfall.

**Governance (open-Q 8.3 / doc 25 §5):** no `CONTRIBUTING.md` or `CODE_OF_CONDUCT.md`.

## 2. Drift

- **CRITICAL — Oracle dropped; v1 ships 5/6 engines.** Plan 23 M8 objective is "full six-engine coverage" / "completes the full six-engine v1 promise." PROGRESS M8 T4 = "MSSQL acceptance gate; **Oracle deferred**." `src/Platform` has 5 platforms and `src/Driver` 4 drivers — no `OraclePlatform`/`OracleDriver`. The §3.11 "engine-swap guarantee" for the full initial set is unmet. (Cross-ref: [02](02-driver-platform-capabilities.md).)

- **MODERATE — Public entry point diverged from plan 18 / migration map §1.** No `DatabaseSession` aggregate facade and no `SQLCraftFactory::connect(string $driverName, …)` composition root anywhere in `src/`. `examples/01-basic-connection/run.php` wires `PdoConnectionFactory` + `PdoExceptionTranslator` + `SqliteDriver` + `SqlitePlatform` manually. Open-Q 3.1/3.3 debated facade-vs-DI; the code chose manual DI but shipped neither documented convenience facade. (Cross-ref: [07](07-security-events-plugins-api.md).)

- **MINOR — `integration.yml` is stale.** The matrix still lists `oracle-xe: 21` and installs `pdo_oci`, but there are no Oracle tests (the `--group=oracle-xe` job would collect nothing).

- **MINOR — `SchemaManager` became a 32-method rich facade**, contrary to open-Q 3.1's "pure aggregate, zero logic of its own" best guess (doc 25 §4 warned this could re-derive a god-object).

- **MINOR — PROGRESS.md M6 has duplicate/conflicting task lines** (T5–T8 each appear as both `[ ] not started` and `[x] … green`), indicating sloppy tracking though the work appears done.

## 3. Unresolved Open Questions (plan 24)

**Resolved in code:**
- §1.1 streaming shape — generators implemented
- §5.3 `StatementSplitter` — hand-rolled state machine, no `nikic/php-parser`
- §4.2 JSON/XML export — SQL/CSV/TSV only (consistent with deferral)
- §2.1 Oracle-CI licensing — mooted by deferring Oracle entirely, not the planned manual-matrix fallback

**Still open:**
- §1.2 SQLite WAL vs `TransactionManager` — no WAL test matrix
- §1.3 PDO persistent connections — no spike; not documented as unsupported
- §4.3 concrete metadata cache — only `NullMetadataCache` shipped
- §8.1 Packagist name — `composer.json` still `"vendor/sqlcraft"` placeholder
- §8.3 governance — no CONTRIBUTING/CODE_OF_CONDUCT
- §2.3 MariaDB `INFORMATION_SCHEMA` divergence — `MariaDbPlatform` exists but no evidence of the systematic diff
- §6.2 multi-patch PHP CI matrix — `ci.yml` pins only `php: ['8.4']`

## 4. Faithful to Plan

- **Five working platform/driver stacks** with live integration coverage (MySQL, MariaDB, PostgreSQL, SQLite, SQL Server) per roadmap M2–M8.
- **A real contract suite** exists (`tests/Contract/`) — thin, but present and wired into CI.
- **Streaming results, N+1-safe batch introspection, approximate pagination counts** — the three headline performance commitments (21 §3/§6/§7).
- **Testcontainers-based integration** per plan 20 §3 (`docker-compose.yml`, Makefile targets).
- **Full M0–M9 milestone chain green** per PROGRESS.md, with commit references per task.
- **Tooling layout** matches plan 19 §6 (`tools/generate-contract-report.php`, `tools/rector-dry-run.sh`).

## 5. Summary

The core architectural commitments are largely realized — five working platform/driver stacks, a real contract suite, streaming results, N+1-safe batch introspection, approximate pagination counts, and the full M0–M9 milestone chain green. However, **v1.0 is genuinely blocked**: the Infection mutation gate (57%/75% vs required 80%/90%) fails, and **Oracle — one of the six committed v1 engines — was silently deferred**, leaving the CI matrix stale and the full engine-swap guarantee unmet. Secondary but systematic shortfalls cluster in the performance plan (no `LazyCollection`, prepared-statement LRU, keyset pagination, row-cap exceptions, concrete metadata cache, or benchmarks), the test plan (thin contract suite, introspection-only golden files, no property-based or memory-regression tests), and uncovered feature-inventory items (cross-table search, maintenance ops, event scheduler) — plus an undocumented public-API drift away from the planned `DatabaseSession`/`SQLCraftFactory` facade.
