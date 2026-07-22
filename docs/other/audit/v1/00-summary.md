# 00 — v1 Plan-vs-Implementation Audit: Summary

> **Status:** Final
> **Audit date:** 2026-07-21
> **Baseline:** `master @ 6d50506` ("docs(release): record mutation threshold blocker"), clean working tree
> **Scope:** `docs/plans/00–25` vs `src/`, `tests/`, CI configuration
> **Method:** eight parallel area audits, each comparing specific plan documents against the corresponding `src/` directories; findings are evidence-based (plan section + file path)

---

## 1. Verdict

The implementation faithfully realizes the **structural surface** of the plans — the capability enum, segregated platform interfaces, domain model, event taxonomy, and five working engine stacks all match their designs closely. Milestones M0–M9 are green.

However, the audit found **six critical gaps**, a recurring pattern of **silently de-scoped subsystems** (Oracle, write query builders, compression, connection decorators), and a cluster of **dead options** — planned parameters that exist on DTOs but are never read by any code path. The v1.0.0 tag is blocked by the mutation-testing gate (MSI 57% / covered 75% vs required 80% / 90%).

## 2. Critical Gaps

| # | Finding | Plan ref | Evidence | Detail |
|---|---------|----------|----------|--------|
| 1 | **Oracle — 1 of 6 promised v1 engines — absent.** No platform, driver, introspection, or capability matrix. "Deferred" at M8 means empty `.gitkeep` dirs. Roadmap 23 promised "full six-engine v1 promise". | 08 §5, 09 §6, 23 M8 | `src/Platform/Oracle/`, `src/Driver/Oracle/` contain only `.gitkeep`; `.github/workflows/integration.yml` still lists `oracle-xe: 21` (stale) | [02](02-driver-platform-capabilities.md) |
| 2 | **Connection decorators missing: Pool / Lazy / ReadReplica** — the headline of the overview diagram. Plus `ConnectionManager` (named multi-connection registry) and the `CredentialProvider` boundary; credentials are inlined on `ConnectionParameters` instead. | 00 diagram, 10 §4/§5.2/§13 | zero grep matches across `src/` | [03](03-connection-layer.md) |
| 3 | **Import has no built-in sources; no compression anywhere.** Plan 14 §6.1 specifies four `ImportSourceInterface` implementations — zero exist (round-trip tests hand-roll anonymous classes). Gzip/Bzip2 sinks and transparent decompression entirely absent. | 14 §2.1, §6.1 | `grep "implements ImportSourceInterface"` → 0; `src/Export/` has only `ResourceSink`, `StringBufferSink` | [06](06-import-export.md) |
| 4 | **Export DDL bypasses `DdlBuilder` — lossy schema dumps.** `ExportSource::getTableDdl()` hand-rolls a minimal `CREATE TABLE` (name + type + NOT NULL + inline PK). Lost: defaults, auto-increment, unique/check constraints, indexes, FKs, collation; composite PKs mis-rendered. `includeTriggers/includeRoutines/includeEvents`, `dataStyle` (InsertUpdate/TruncateInsert), and `databaseStyle` are **dead flags**. | 14 §2.4/§3.1/§3.2 | `src/Metadata/ExportSource.php`, `src/Export/SqlFormatWriter.php` | [06](06-import-export.md) |
| 5 | **DDL builders' `execute()` violates plan 13's explicit invariant** ("Builders never call `ConnectionInterface::execute()` directly"). All 19 builders call `$connection->execute()` directly, skipping events, cache invalidation, and SQLite recreation. Direct `AlterTableBuilder::execute()` on SQLite emits **invalid SQL**. | 13 §2.2/§8/§10 | `src/DDL/*Builder.php` vs `src/DDL/DdlManager.php` | [04](04-schema-ddl.md) |
| 6 | **v1.0 gate unmet:** Infection MSI 57% / covered 75% vs required 80% / 90%. Thresholds live only in the composer script, not `infection.json.dist`; `composer ci` does not run infection at all. | 20 §6/§12 | `docs/PROGRESS.md` M10 T3 | [08](08-testing-performance-roadmap.md) |

## 3. Moderate Gaps (condensed)

- **Connection (10):** isolation-level parameter is a no-op (never executes `SET TRANSACTION ISOLATION LEVEL`); SSL options never mapped to PDO attributes; connections opened eagerly (plan: lazy); `PdoPreparedStatement` re-prepares on every call. → [03](03-connection-layer.md)
- **Schema/DDL (11, 13):** MSSQL introspection SQL exists but `SchemaManagerFactory` throws for `sqlserver`; `TableSearchService`, `PrivilegeInspector` implementation, 3 of 4 cache implementations, and schema-diff structures missing; cache `invalidate*()` never called; SQLite recreation runs for *every* ALTER (no `needsRecreation()` discrimination) and the column-rename copy map is missing; MySQL `AFTER` positioning and single multi-clause `ALTER TABLE` not implemented. → [04](04-schema-ddl.md)
- **Query (12, 05/06/07):** **INSERT/UPDATE/DELETE builders and FK navigation** promised in plans 00, 05 §7, 06 §3, 07 — plan 12 silently dropped them; only `SelectQuery` exists. Multi-resultset iteration (`nextRowset`) missing; per-query timeout has plumbing but zero engine implementations; `ExplainResult::$tree/$json` never populated; `StatementSplitter` lacks PgSQL dollar-quoting. → [05](05-query-engine.md)
- **Import/Export (14):** `Importer`'s 8 KB buffer splits on chunk-boundary `;` inside quoted strings — the exact bug class the plan criticized Adminer for; `statementTimeoutMs` dead option; CSV upsert silently degrades to plain `INSERT` on PgSQL/MSSQL; JSON/XML writers, `FormatRegistry`, `MultiFileSink`, PgSQL FK-deferred ordering, and proper view export missing. → [06](06-import-export.md)
- **Security/API (15–18):** no public entry point (`SQLCraftFactory`/`DatabaseSession`) — *acknowledged deferral in plan 18 §7*; `SecurityGuardInterface` absent (also acknowledged); import `maxStatements` defaults to unlimited (plan: 1,000); `DriverRegistry` does not pre-register built-in drivers. → [07](07-security-events-plugins-api.md)
- **Performance (21):** `LazyCollection`, prepared-statement LRU cache, keyset pagination, row caps (`ResultSetTooLargeException`), concrete metadata cache, and the benchmark suite absent. Streaming, N+1-safe batch introspection, and approximate counts *are* implemented. → [08](08-testing-performance-roadmap.md)
- **Testing (20):** contract suite has 5 tests vs the plan's "single most important test asset"; golden files cover introspection only; no property-based tests; no standing memory-regression group; coverage floors not enforced. → [08](08-testing-performance-roadmap.md)
- **Feature inventory orphans (04):** cross-table search, maintenance ops (ANALYZE/OPTIMIZE/VACUUM/REPAIR), MySQL event scheduler — no milestone, no code. → [08](08-testing-performance-roadmap.md)

## 4. Drift (implemented differently)

- **`DriverRegistry`**: instance-based with constructor injection vs plan 08 §8's static registry — arguably better (DI-friendly), but the planned pre-registration of built-ins moved nowhere since the factory does not exist. → [02](02-driver-platform-capabilities.md)
- **Package layout**: plan 19's per-engine subdirs (`Driver/MySQL/`, `Platform/MySQL/`, …) are empty `.gitkeep`s; classes are flat. `Utilities/` context never created. → [01](01-domain-model.md), [02](02-driver-platform-capabilities.md)
- **Finality vs flavor extensibility**: `PostgreSQLPlatform` is `final`, blocking plan 08 §6's CockroachDB-subclass story; only the MySQL family remains subclassable. → [02](02-driver-platform-capabilities.md)
- **Savepoint naming**: two schemes (`sqlcraft_sp_N` in `PdoConnection`, `sp_<hex>` in `TransactionManager`). → [03](03-connection-layer.md)
- **Housekeeping**: `PROGRESS.md` M6 has duplicate conflicting task lines; `composer.json` name still `vendor/sqlcraft` placeholder (open-Q 8.1); no `CONTRIBUTING.md`/`CODE_OF_CONDUCT.md` (open-Q 8.3); `integration.yml` Oracle job stale. → [08](08-testing-performance-roadmap.md)
- **Plan-internal inconsistency (code is right)**: `CapabilityNotSupportedException` lives in `Capabilities\` per plan 09 §10; plan 05 §9's hierarchy diagram is the outlier. → [01](01-domain-model.md), [02](02-driver-platform-capabilities.md)

## 5. What Is Faithfully Built

- **Capability model**: enum matches plan 09 §2 exactly (all 35 cases); `CapabilitySet` (`has`/`require`/`intersect`), `PlatformCapabilityResolver`'s `always`/`versioned` matrix, and MySQL's matrix all match — plus additive event emission on `require()` failure.
- **Platform contracts**: all five segregated interfaces + composite `PlatformInterface` per plan 08 §3; `MariaDbPlatform extends MySQLPlatform` flavor decision honored.
- **Domain model**: every planned VO/DTO exists; exception hierarchy intact (plus four additive exceptions); `Support` is a true leaf (zero SQLCraft imports) per rule 6.
- **Events**: the full plan-16 catalog (~26 events) exists, is PSR-14 compliant, genuinely emitted, with a working veto/cancellation path (`Before*` → `OperationCancelledException`) — richer than planned.
- **Plugin system is NOT a gap**: plan 17 explicitly rejects a monolithic Plugin class; its three real mechanisms (events, DI interface-swaps, extension interfaces) are substantially present. Only named seams missing: `CredentialProviderInterface`, `FormatReaderInterface`, `DriverRegistryInterface`, `FormatRegistry`.
- All 13 inspector contracts and all 19 DDL builders exist with planned options; M0–M9 milestone chain green.

## 6. Still-Open Questions (plan 24)

| Ref | Question | Status |
|-----|----------|--------|
| 1.2 | SQLite WAL vs `TransactionManager` | open — no WAL test matrix |
| 1.3 | PDO persistent connections | open — no spike, not documented as unsupported |
| 2.3 | MariaDB `INFORMATION_SCHEMA` divergence | open — no systematic diff evidence |
| 4.3 | Concrete metadata cache | open — only `NullMetadataCache` shipped |
| 6.2 | Multi-patch PHP CI matrix | open — `ci.yml` pins only `php: ['8.4']` |
| 8.1 | Packagist name | open — `composer.json` still `vendor/sqlcraft` |
| 8.3 | Governance docs | open — no CONTRIBUTING/CODE_OF_CONDUCT |

Resolved in code: 1.1 (streaming via generators), 5.3 (hand-rolled `StatementSplitter`), 4.2 (JSON/XML export deferred — consistent), 2.1 (Oracle CI mooted by full deferral, not the planned manual-matrix fallback), 3.1/3.3 (facade-vs-DI settled as manual DI — but neither documented convenience facade shipped).

## 7. Recommended Priority

1. **Decide the v1.0 scope honestly.** Either restore Oracle or amend the roadmap/overview to declare five engines for v1 (and clean the stale CI matrix). Same decision needed for Pool/Lazy/ReadReplica, write query builders, and compression — the overview diagram currently overpromises.
2. **Fix the two correctness hazards.** Export's lossy DDL (route through `DdlBuilder`) and the import chunk-boundary split; make builder `execute()` route through `executeDdl()` or remove it.
3. **Wire or remove dead options.** Isolation levels, SSL, `statementTimeoutMs`, `includeTriggers/Routines/Events`, `dataStyle`, `databaseStyle`, `ExplainResult::$tree/$json`.
4. **Attack the infection gate** (57% → 80% MSI) to unblock the v1.0.0 tag.

## 8. Area Reports

| Doc | Area | Plans | Source dirs |
|-----|------|-------|-------------|
| [01](01-domain-model.md) | Domain Model & Package Structure | 05, 06, 19 | `DTO/`, `ValueObjects/`, `Collections/`, `Exceptions/`, `Support/` |
| [02](02-driver-platform-capabilities.md) | Driver, Platform & Capability Model | 08, 09 | `Driver/`, `Platform/`, `Capabilities/` |
| [03](03-connection-layer.md) | Connection Layer | 10 | `Connection/` |
| [04](04-schema-ddl.md) | Schema Introspection & DDL Services | 11, 13 | `Metadata/`, `Schema/`, `DDL/` |
| [05](05-query-engine.md) | Query Engine & Execution | 12 | `Query/`, `Execution/` |
| [06](06-import-export.md) | Import / Export | 14 | `Import/`, `Export/` |
| [07](07-security-events-plugins-api.md) | Security, Events, Plugins & Public API | 15, 16, 17, 18 | `Security/`, `Events/`, `src/` root |
| [08](08-testing-performance-roadmap.md) | Testing, Performance, Roadmap & Open Questions | 04, 20–25 | `tests/`, CI, infection config |

**Severity key:** CRITICAL = core planned artifact missing or incorrect behavior; MODERATE = behavioral/structural divergence or planned feature absent; MINOR = naming, placement, cosmetic.
