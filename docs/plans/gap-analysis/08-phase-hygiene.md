# Phase 8 — Docs, config & tracker hygiene

> Depends on: nothing. Run in parallel with any phase (touches only docs + config).
> Release-blocking: no by itself, but the release gate should not ship with lying docs.
> Closes audit findings: summary §4 (QWEN, overview reading guide, PROGRESS, deptrac); 02 findings 3/6/9/10/11; 01 M-2/M-5/L-3; Oracle drop (README §4).

Low-risk, high-clarity cleanup. Every item here is a doc or config file that currently
misleads a reader about what exists. None touch `src/` behavior.

---

## 8.1 Rewrite `QWEN.md`

**Problem:** badly stale and self-contradicting. Says "Currently in Milestone M2" (actual: M10);
lists Oracle via `pdo_oci` (contradicts README + this plan's Oracle drop); draws the rejected
Facade/ServiceContainer and Pool/Lazy/ReadReplica connections that don't exist. (Summary §4.)

**Work:** rewrite against current state — real milestone status, five supported engines (no
Oracle), the `SQLCraftFactory` + `DatabaseSession` entry point (once Phase 3 lands), no Facade,
no Pool/Lazy/ReadReplica.

---

## 8.2 Regenerate `00-overview.md` reading guide

**Problem:** the 26-row reading-guide table lists filenames that don't exist
(`06-connection-layer.md`, `07-driver-platform.md`, `10-ddl-service.md`, `17-exceptions.md`,
`18-value-objects.md`, `23-streaming-memory.md`, …). Actual files have different numbers/names.
Every area agent hit this. (Summary §4, Audit 02 finding 11.)

**Work:** regenerate the table from the actual `ls docs/plans/` output. Also fix the ASCII
diagram: remove `ConnectionPool / LazyConnection / ReadReplicaConnection` (or mark deferred),
remove `OraclePlatform`, and update the top-of-stack entry from `Facade / ServiceContainer` to
`SQLCraftFactory / DatabaseSession`.

---

## 8.3 Clean `PROGRESS.md`

**Problem:** 12 stale `- [ ] … not started` lines sit directly above their `- [x] … green`
twins (M6 T5–T8, all M7 T1–T7 + gate). One `commit pending` placeholder at line 101 (M6 T8 gate).
(Summary §4.)

**Work:** delete the dead `[ ]` twins; resolve `commit pending` to the real commit hash. Then
**correct the milestone status** — M4/M5/M6/M7/M8/M9 are not truly green until Phases 1/4/5/6/7
land; reflect that honestly (e.g. mark them "green pending gap-closure" with a pointer to this
plan) rather than leaving false greens.

---

## 8.4 `deptrac.yaml` fixes

**Problem:** several ruleset issues. (Audit 02 finding 10, 01 M-5, summary §4.)

**Work:**
1. Remove `Connection`, `Platform`, `Export`, `Import` from the `Contracts` allowed-deps list — Contracts must depend on no concrete adapter layer (hexagonal guarantee).
2. Remove `Capabilities` from the `Exceptions` allowed-deps list (or document the deliberate deviation).
3. Resolve the `Utilities` layer: it points at `src/Utilities/.*` which doesn't exist. Either Phase 2 §2.4 created the dir (keep the layer) or delete the layer here.
4. The `Security` layer is defined but nothing depends on it — after Phase 4 adds security services, confirm the ruleset lists Security's real deps (`Contracts`, `ValueObjects`, `Exceptions`, and whatever the managers need).

**Acceptance:** `vendor/bin/deptrac` green with no layer pointing at a nonexistent directory and
no Contracts→concrete-adapter permission.

---

## 8.5 Doc-drift reconciliation (authoritative-doc corrections)

- **Oracle drop (this plan §4):** remove Oracle from `00-overview.md` diagram, `07-module-breakdown.md` §6–7, `08-driver-architecture.md` §5, `QWEN.md`. Add one "Deferred: Oracle" line to the roadmap + `04-feature-inventory.md`. Optionally delete `src/Driver/Oracle/` + `src/Platform/Oracle/` `.gitkeep` dirs.
- **`Capability` enum doc drift (Audit 01 M-2):** doc 02 §9 lists stale case names (`Schemas`, `StoredProcedures`, `QueryTimeout` string values) contradicting doc 09 §2 + code. Mark doc 02 §9 "superseded by doc 09" or reproduce doc 09's exact cases.
- **`DriverRegistry` static→instance (Audit 02 finding 9):** update `08-driver-architecture.md` §8 + `07-module-breakdown.md` §6 to the instance-based API; fix every `DriverRegistry::register()` static-call example.
- **`AbstractDriver` / `QueryLogger` (Audit 02 findings 5, 8):** either add these documented-but-absent helpers or annotate them as deferred/optional in the docs. Decide, don't leave silent.
- **`SecretRedactor` undocumented (Audit 01 L-3):** add it to doc 07 §10 Support module list.
- **Namespace placement (Audit 05 §7):** move `StatementSplitter` → `src/Execution/` (namespace `SQLCraft\Execution`); `TransactionManager` → `src/Execution/` (or annotate the Connection-layer placement as intentional). `PaginatorInterface` + `QueryHistoryEntry` moves are in Phase 2 §2.1.

**Acceptance:** no authoritative doc names Oracle as supported; enum docs point to one source of
truth; `DriverRegistry` examples match the instance API; misplaced classes relocated.

---

## Phase 8 exit criteria

- `QWEN.md` and `00-overview.md` describe what actually exists.
- `PROGRESS.md` has no duplicate/placeholder lines and honest milestone status.
- `deptrac.yaml` enforces the real hexagonal boundary with no dead layers.
- Oracle removed from every authoritative doc; all doc-drift items reconciled.
