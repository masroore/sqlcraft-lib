# Gap-Closure Implementation Plan

> Created: 2026-07-21
> Source: `docs/audit/v2/` (7 area reports + `00-summary.md`)
> Status: **PLAN ONLY — not yet executed.** No source has been modified.
> Scope decision: **Oracle is dropped for this version.** See §4.

---

## 1. Why this plan exists

The v2 audit found `PROGRESS.md` marks M0–M9 green and frames v1.0 as blocked
solely on the Infection mutation threshold (57% MSI vs 80% target). That framing
materially understates the gap. Three feature blocks promised across multiple
planning docs were dropped by the detailed design docs and never built, while
their milestones report green:

1. **Security write-side** (users/roles/GRANT/REVOKE, `SecurityGuardInterface`) — absent; M9 green.
2. **DML builders** (INSERT/UPDATE/DELETE) — absent; M6 green.
3. **SQL Server introspection** — `SqlServerMetadataFactory` missing, so `SchemaManagerFactory` throws for any MSSQL connection; M8 green.

Alongside these, a recurring class of "defined-but-never-wired" seams exists in
every area: cache invalidation events that no listener consumes, export flags
that no writer reads, DDL builders that route around the one class firing events.

**Raising mutation coverage on code that omits its promised features would produce
a well-tested but incomplete v1.0.** This plan closes the correctness gaps first,
then makes explicit build-or-defer decisions on the scope holes, then re-runs the
release gate.

---

## 2. Principles for execution

- **Correctness before coverage.** Do not chase the MSI threshold until Phase 1 lands. Mutation-testing code that silently drops features is wasted effort.
- **Contract-first.** Every new subsystem gets its interface in `src/Contracts/` before the concrete adapter, matching the hexagonal boundary Deptrac enforces.
- **Capability-gated by default.** Any engine-specific operation calls `$caps->require(Capability::X)` before issuing SQL. This is the single most-violated contract in the current code.
- **One execution path.** DDL and DML execution route through `QueryExecutor`/`DdlManager` so events fire and cache invalidation happens. No builder calls `$connection->execute()` directly.
- **Each phase ends green.** A phase is done when its new code has tests, `make build` passes (PHPStan, Psalm, Deptrac, cs-fixer), and the milestone claims it backs are actually true.
- **Update the promise docs, not just the code.** When a feature is built or deferred, reconcile `00-overview.md` and `04-feature-inventory.md` in the same change so the docs stop lying.

---

## 3. Phase map (dependency-ordered)

| Phase | Title | Depends on | Release-blocking? |
|-------|-------|-----------|-------------------|
| 1 | Correctness — make green milestones actually true | — | **Yes** |
| 2 | Foundation contracts & missing primitives | — | **Yes** (unblocks 3–6) |
| 3 | Consumer entry point & wiring | 2 | **Yes** |
| 4 | Security write-side | 2, 3 | **Yes** (M9 claim) |
| 5 | Query completeness (DML, navigation, caches) | 2, 3 | **Yes** (M6 claim) |
| 6 | Import/Export completeness | 2, 3 | Partial |
| 7 | DDL scope reconciliation (build-or-defer) | 1 | Decision gate |
| 8 | Docs, config & tracker hygiene | — (parallel) | Gate cleanup |
| 9 | Release gate — mutation coverage & v1.0 tag | 1–8 | **Final** |

Phases 1, 2, and 8 can start immediately and in parallel (8 touches only docs/config).
Phases 3–6 depend on the foundation from 2. Phase 9 is last by definition.

---

## 4. Oracle decision (this version)

Oracle is **dropped from v1.0** and deferred to a future release. Rationale: the
driver and platform were never built (`src/Driver/Oracle/` and `src/Platform/Oracle/`
hold only `.gitkeep`), README already states "Oracle intentionally deferred," and
carrying Oracle references in the authoritative docs (`00-overview.md`,
`07-module-breakdown.md`, `08-driver-architecture.md`, `QWEN.md`) is pure doc-drift.

Concrete actions (tracked in Phase 8):
- Remove Oracle from the supported-engine lists in `00-overview.md`, `07-module-breakdown.md` §6–7, `08-driver-architecture.md` §5, and `QWEN.md`.
- Add a single "Deferred: Oracle" line to the roadmap and feature inventory.
- Do **not** add Oracle branches to any new code (upsert mapping, capability matrices, metadata factories). Where a `match` would need an Oracle arm, omit it; the `default` throw is correct.
- Keep the empty `src/Driver/Oracle/` and `src/Platform/Oracle/` dirs or delete them — either is fine; deletion is cleaner.

The five supported engines for v1.0: **MySQL, MariaDB, PostgreSQL, SQLite, SQL Server.**

---

## 5. Severity → phase cross-reference

| Audit finding | Severity | Phase |
|---|---|---|
| SqlServerMetadataFactory missing (MSSQL crash) | CRITICAL | 1 |
| DDL builder execute() bypasses QueryExecutor | HIGH | 1 |
| Metadata cache invalidation dead circuit | HIGH | 1 |
| Inspectors skip capability checks | HIGH | 1 |
| Export DumpOptions flags never read | HIGH | 1/6 |
| AllDatabases scope → single-DB | HIGH | 1/6 |
| MariaDB sequence advertises but throws | HIGH | 1 |
| Import naive `;` splitter | HIGH | 1/6 |
| Missing contracts (Schema/Query/Security guard) | HIGH | 2 |
| LazyCollection absent | HIGH | 2 |
| QueryTimeoutException + capability absent | HIGH | 2 |
| Utilities module absent | MED | 2 |
| SQLCraftFactory / DatabaseSession absent | HIGH | 3 |
| ConnectionManager absent | HIGH | 3 |
| CredentialProvider + Credential VO absent | HIGH | 3 |
| FormatRegistry absent | MED | 3 |
| SecurityGuard + User/Privilege managers absent | CRITICAL | 4 |
| INSERT/UPDATE/DELETE builders absent | HIGH | 5 |
| Fk navigation / cross-table search / BLOB stream | MED | 5 |
| Metadata cache impls (3) absent | MED | 5 |
| Import sinks/sources, topological sort absent | MED | 6 |
| DDL builder groups + ALTER arms dropped | HIGH | 7 |
| QWEN/overview/PROGRESS/deptrac hygiene | LOW–MED | 8 |
| Mutation coverage below threshold | — | 9 |

Each phase file (`01`–`09`) lists concrete work items, target files, acceptance
criteria, and the audit finding IDs it closes.
