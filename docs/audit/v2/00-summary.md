# SQLCraft Audit v2 — Aggregate Summary

> Date: 2026-07-21
> Method: read-only. Plan docs (`docs/plans/00–25`) vs implementation (`src/`) vs progress tracker (`docs/PROGRESS.md`).
> 7 area reports fanned out; this file merges them and adds cross-cutting findings that single-area agents could not see.
> PROGRESS.md marks M0–M9 green and M10 blocked only on the Infection MSI threshold. This audit finds that framing **materially understates the gap** — several M4/M5/M7/M9 acceptance criteria are not actually met.

Area reports:
- [01-foundation.md](01-foundation.md)
- [02-connection-driver-platform.md](02-connection-driver-platform.md)
- [03-metadata-schema.md](03-metadata-schema.md)
- [04-ddl.md](04-ddl.md)
- [05-query-execution.md](05-query-execution.md)
- [06-import-export.md](06-import-export.md)
- [07-security-events-plugin.md](07-security-events-plugin.md)

---

## 1. The headline

The project is **broad but hollow in three places**. The type-heavy foundation (Contracts, VOs, DTOs, Collections, Events, Exceptions) is largely complete and honest. But three promised feature blocks that appear across *multiple* planning docs were quietly dropped by the detailed docs and never built, while the progress tracker reports their milestones green:

1. **Security write-side (users/roles/GRANT/REVOKE) — absent.** `src/Security/` contains only `IdentifierQuoter` + `OperatorValidator`. `SecurityGuardInterface` (promised in the Contracts table, doc 07 §1) does not exist. All 6 write operations from feature-inventory §18 are missing. M9 "security & events" is marked green.
2. **DML builders (INSERT/UPDATE/DELETE) — absent.** `src/Query/` has only the SELECT surface. Feature-inventory §14 and module-breakdown §9 both promise INSERT/UPDATE/DELETE/clone. Writing rows currently requires hand-rolled raw SQL — the exact thing the library exists to prevent.
3. **SQL Server introspection — non-functional.** Driver + platform shipped (M8 green), but `SqlServerMetadataFactory` is absent, so `SchemaManagerFactory` throws for any MSSQL connection. The "engine-swap guarantee" (doc 18 §3.11) is false for MSSQL.

None of these three are tracked as deferred anywhere. They are the highest-value findings because each is promised in several docs and silently absent.

---

## 2. Blocker inventory (CRITICAL / HIGH)

| # | Finding | Area | Milestone claimed | Evidence |
|---|---------|------|-------------------|----------|
| B1 | `SecurityGuardInterface` promised in Contracts table, never created | 07 §1.2.1 | M9 green | absent from `src/Contracts/` |
| B2 | User/role/GRANT/REVOKE write-side entirely absent (6/6 ops) | 07 §1.2.2 | M9 green | only read-side `UserInspector` exists |
| B3 | `InsertQuery`/`UpdateQuery`/`DeleteQuery` absent | 05 §2 | M6 green | only `SelectQuery` in `src/Query/` |
| B4 | `SqlServerMetadataFactory` absent → MSSQL introspection throws | 03 | M8 green | `SchemaManagerFactory` `InvalidArgumentException` for MSSQL |
| B5 | Metadata cache invalidation is a dead circuit | 03 | M4 green | events fire, no listener calls `invalidate*` |
| B6 | Inspectors skip capability checks entirely | 03 | M4 green | no `$caps->require()` in any inspector |
| B7 | DumpOptions `includeTriggers/Routines/Events/UserTypes` never read → export omits that DDL | 06 §H2 | M7 green | flags declared, zero readers |
| B8 | `ScopeKind::AllDatabases` routes to single-DB branch | 06 §H3 | M7 green | no multi-DB iteration |
| B9 | DDL builder `execute()` bypasses `QueryExecutor` → fires no events; SQLite recreation + events only work via `DdlManager` | 04 §H2/§H3 | M5/M9 green | 18 builders call `$connection->execute()` directly |
| B10 | MariaDB 10.3+ sequence: capability advertises support, render inherits MySQL throw | 04 §H4 | M8 green | `renderCreateSequenceStatement` |
| B11 | 9 DDL builder groups + 3 ALTER arms dropped (AlterDatabase, RenameDatabase, CopyTable, MoveTable, Event×3, Type×3, ALTER view/routine/trigger) | 04 §H1/§H5 | — | promised in doc 04, absent from doc 13 + code |
| B12 | No consumer entry point: `SQLCraftFactory`/`DatabaseSession` absent | 07 §3.3 | — | consumers must hand-wire every service |
| B13 | `CredentialProvider` subsystem + `Credential` VO absent; `ConnectionFactory` ignores credentials | 02, 07 §1.2.3 | M2 green | no provider, no VO |
| B14 | Importer uses naive `str_ends_with(';')` splitter, not the promised stream state-machine | 06 §H1 | M7 green | reintroduces the edge-case bug the plan claims to kill |
| B15 | `LazyCollection` absent — large-result-set performance contract undeliverable | 01 | M1 green | module-breakdown §4 |

---

## 3. Cross-cutting patterns (only visible when merging areas)

### 3.1 Promise repeated across docs, dropped in the detail doc — the highest-value class
Same failure mode recurs: overview / feature-inventory / module-breakdown promise a capability; the *detailed* design doc for that area never mentions it; code follows the detailed doc.
- Users/roles/privileges: overview diagram + feature-inventory §18 + module-breakdown §1/§10 → doc 15 covers only *input-validation* security, not user management → code has neither. (B1, B2)
- DML builders: feature-inventory §14 + module-breakdown §9 → doc 12 is SELECT-centric → only SELECT built. (B3)
- Event/Type/Copy/Move DDL: feature-inventory §2/§3/§11/§13 → doc 13 omits them → absent. (B11)

**Recommendation:** these are scope-reconciliation decisions, not bugs to silently fix. Either build them or add explicit deferral markers to overview + feature-inventory (the authoritative promise docs).

### 3.2 "Defined but never wired" seams recur in every area
- Metadata: `MetadataCacheInterface` invalidation never called (B5); `PrivilegeInspectorInterface` has no impl (03).
- Export: 4 `DumpOptions` flags never read (B7); `ImportOptions::$statementTimeoutMs` never consumed (06).
- DDL: builders route around the one class (`DdlManager`) that fires events (B9).
- Query: `BackwardKeyMeta` DTO exists, no navigator uses it (05 §3).
- Config: `deptrac.yaml` declares a `Utilities` layer pointing at `src/Utilities/` which **does not exist** (silent no-op collector); `Security` layer defined but nothing depends on it.

Pattern: the contract/DTO/flag was built during its milestone, the wiring that gives it effect was deferred and forgotten. A "seam liveness" test (assert every declared cache/flag/event has ≥1 caller) would catch the whole class.

### 3.3 Facade path shifts across docs
Overview ASCII shows `SQLCraft\Facade / ServiceContainer`; doc 18 §2.1 *rejects* a static facade for `SQLCraftFactory` + `DatabaseSession`; code has none of the three. QWEN.md still draws the rejected facade. The design moved, the diagrams didn't, and nothing got built. (B12)

### 3.4 Namespace/layer placement drift
`StatementSplitter`, `TransactionManager`, `PaginatorInterface`, `QueryHistoryEntry` all sit in a different module/namespace than their contract or the plan dictates (05 §7). `deptrac`'s `Contracts` ruleset permits imports from concrete `Connection`/`Platform` layers (02), so the hexagonal boundary these placements would violate isn't actually enforced.

---

## 4. Doc-vs-doc and tracker hygiene

- **QWEN.md is badly stale and self-contradicting.** Status: "Currently in Milestone M2 (Connection Layer)" (actual: M10). "Supported Databases" lists **Oracle (via `pdo_oci`)** — directly contradicts README ("Oracle intentionally deferred, not part of this release"). Draws the rejected Facade/ServiceContainer and lists Pool/Lazy/ReadReplica connections that don't exist. **Fix: rewrite QWEN.md against current state.**
- **`00-overview.md` reading guide is wrong.** Its 26-row table lists filenames that do not exist (`05-namespace-structure.md`, `06-connection-layer.md`, `07-driver-platform.md`, `10-ddl-service.md`, `11-query-service.md`, `12-execution-service.md`, `17-exceptions.md`, `18-value-objects.md`, `19-collections.md`, `23-streaming-memory.md`, …). Actual files have different numbers/names. Every area agent hit this. **Fix: regenerate the reading-guide table from `ls docs/plans/`.**
- **PROGRESS.md hygiene:** 12 stale `- [ ] … not started` lines duplicated directly above their `- [x] … green` counterparts (M6 T5–T8, all of M7 T1–T7 + gate). One `commit pending` placeholder at line 101 (M6 T8 gate). **Fix: delete the dead `[ ]` twins and resolve the placeholder to a real commit.**
- **Correction to area report 07:** it lists `DriverRegistry` as "not found in src/". It **does** exist (`src/Driver/DriverRegistry.php`, instance-based). Only `FormatRegistry` is genuinely absent. Report 02 has the accurate reading.

---

## 5. Fix plan — workstreams ordered by dependency

Ordered so each stream unblocks the next. Streams A–C are release-blocking correctness; D–E are completeness/scope; F is hygiene and can run anytime in parallel.

### Workstream A — Make claimed-green milestones actually true (correctness blockers)
Do first; these are "we said done, it isn't."
1. **A1** SQL Server introspection: implement `SqlServerMetadataFactory`, wire into `SchemaManagerFactory` (B4).
2. **A2** DDL event/safety routing: route builder `execute()` through `QueryExecutor::executeDdl()` so events fire and SQLite `TableRecreationStrategy` runs on the direct path (B9).
3. **A3** MariaDB sequence rendering: override `renderCreateSequenceStatement` so advertised capability doesn't throw (B10).
4. **A4** Metadata cache invalidation: add a listener on `AfterDdlExecuted`/`SchemaChangedEvent` that calls `invalidateTable/invalidateDatabase` (B5).
5. **A5** Inspector capability gates: add `$caps->require(...)` to capability-gated inspectors (B6).
6. **A6** Export DDL flags + scope: make `DumpOptions` flags actually emit trigger/routine/event/type DDL; implement `ScopeKind::AllDatabases` iteration (B7, B8).
7. **A7** Import splitter: replace `str_ends_with(';')` with the stream state-machine `StatementSplitter` over a resource (B14).

### Workstream B — Consumer entry point (unblocks usability + downstream wiring)
Depends on A being coherent. `DriverRegistry` already exists; build on it.
1. **B1** Implement `SQLCraftFactory` + `DatabaseSession` per doc 18 §2–3 (B12).
2. **B2** Implement `FormatRegistry` (export/import format extension point; doc 14 §7). (`DriverRegistry` exists — do not recreate.)

### Workstream C — Security (the largest single gap; blocks the M9 "green" claim)
1. **C1** `SecurityGuardInterface` + `PrivilegeGuard` delegating to `PrivilegeInspectorInterface` (B1).
2. **C2** `UserManagerInterface` + `PrivilegeManagerInterface` with per-engine GRANT/REVOKE/CREATE USER DDL, capability-gated (B2). Start MySQL + PostgreSQL.
3. **C3** `Credential` VO + broaden `#[\SensitiveParameter]` across password-accepting signatures; wire `SecretRedactor` into the exception hierarchy (B13, 07 §5).
4. **C4** Implement `PrivilegeInspector` (contract exists, no impl) and `TableSearchService` with `$rowCap` (07 §6.5).

### Workstream D — Query completeness
1. **D1** `InsertQuery`/`UpdateQuery`/`DeleteQuery` VOs + renderers following the SelectQuery pattern (B3).
2. **D2** FK navigation (`FkNavigator` using `BackwardKeyMeta`) + cross-table search + BLOB streaming (05 §3, §4).
3. **D3** `LazyCollection` for large result sets (B15).
4. **D4** `ImportOptions::$maxStatements` safe non-null default (07 §6.3).

### Workstream E — DDL scope reconciliation (decision, then build-or-defer)
For each of the 9 dropped builder groups + 3 ALTER arms (B11): decide build-for-v1 vs explicit-deferral. If deferring, add markers to `00-overview.md` + `04-feature-inventory.md`. If building: AlterDatabase, RenameDatabase, CopyTable, MoveTable, Event×3, Type×3, ALTER view/routine/trigger.

### Workstream F — Docs & config hygiene (parallel, low-risk)
1. **F1** Rewrite QWEN.md against current state; remove Oracle/Facade/Pool claims (§4).
2. **F2** Regenerate `00-overview.md` reading-guide table from actual filenames (§4).
3. **F3** Clean PROGRESS.md: delete duplicate `[ ]` twins, resolve `commit pending` (§4).
4. **F4** `deptrac.yaml`: drop the dead `Utilities` layer (or create `src/Utilities/`); tighten the `Contracts` ruleset so it doesn't permit concrete `Connection`/`Platform` imports (02, 03.2).
5. **F5** Fix namespace/layer placement of `StatementSplitter`, `TransactionManager`, `PaginatorInterface`, `QueryHistoryEntry` (05 §7).

---

## 6. Bottom line on the M10 release gate

PROGRESS.md frames v1.0 as blocked solely on the Infection MSI threshold (57% vs 80%). That is the *least* of it. The real blockers are the correctness gaps in Workstream A (features marked green that don't work) and the two large scope holes (Security write-side, DML builders) that no tracker line acknowledges. **Raising mutation coverage on code that omits its promised features would produce a well-tested but incomplete v1.0.** Recommend: close Workstream A, make an explicit build-or-defer decision on B/C/D/E, then re-run the M10 gate.
