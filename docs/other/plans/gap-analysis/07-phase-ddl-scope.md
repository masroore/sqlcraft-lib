# Phase 7 — DDL scope reconciliation (build-or-defer)

> Depends on: Phase 1 (DDL execution routing must be correct first).
> Release-blocking: no — this is a **decision gate**, then build or document.
> Closes audit findings: 04 §1.2 (H1/H5, dropped builders); 04 §2 (interface drift M1/M2/M4); 04 §3.2 (SQLite schema); 04 §6 (throw-timing).

Nine builder groups + three ALTER arms were promised in `04-feature-inventory.md`, silently
dropped when `13-ddl-services.md` narrowed scope, and never built. This is a **scope decision,
not a bug to silently fix**. For each group, decide build-for-v1 vs explicit-deferral, then act.
Do not leave the current state (promised in doc 04, absent from doc 13, absent from code, no note).

---

## 7.1 The dropped builder groups (Audit 04 §1.2)

| Group | Feature (doc 04) | Capability | Recommend |
|---|---|---|---|
| `AlterDatabaseBuilder` | Alter database charset/collation | `DatabaseManagement` | **Build** — small, common admin op |
| `RenameDatabaseBuilder` | Rename database | `DatabaseRename` | Defer — engine support uneven, niche |
| `CopyTableBuilder` | Copy table | `TableCopy` | **Build** — core admin workflow |
| `MoveTableBuilder` | Move table between DBs/schemas | `TableMove` | Defer — cross-DB semantics vary widely |
| `AlterViewBuilder` | ALTER arm of create/alter/drop view | baseline | **Build** — "create/alter/drop" promised as a set |
| `AlterRoutineBuilder` | ALTER arm of procedure | `StoredProcedures` | **Build** — completes the set |
| `AlterTriggerBuilder` | ALTER arm of trigger | `Triggers` | Defer — most engines drop+recreate; document that pattern |
| `CreateEventBuilder`/`DropEventBuilder`/`AlterEventBuilder` | Scheduled events | `Events` | Defer — MySQL/MariaDB-only, low priority |
| `CreateTypeBuilder`/`DropTypeBuilder`/`AlterTypeBuilder` | User-defined types | `UserDefinedTypes` | Defer — PostgreSQL-centric, complex |

Recommendations are a starting proposal, not a mandate — the project owner makes the final call
per group. The rule: **every group ends either built-with-tests or explicitly-deferred-in-docs.**

**For each "Build" group:**
1. `src/DDL/XxxBuilder.php` following the existing builder pattern (`toSql(PlatformInterface)`, no direct `execute()` — routes through `DdlManager` per Phase 1 §1.2).
2. Add the render method(s) to `DdlDialectInterface` + `AbstractPlatform` + per-engine overrides; unsupported engines `throw $this->unsupported(Capability::X)`.
3. Add the `Capability` enum case if missing (reconciles part of Audit 01 H-5).
4. Unit + golden SQL tests per engine.

**For each "Defer" group:**
1. Add `> Status: Deferred to a future version` to `04-feature-inventory.md` at that feature.
2. Add a matching line in the roadmap.
3. Do **not** add the capability case unless a deferred marker references it; if added, mark it reserved.

**Acceptance:** zero builder groups remain in the promised-but-silent state. Each is built+tested
or carries an explicit deferral note in the authoritative doc.

---

## 7.2 `DdlDialectInterface` drift (Audit 04 §2)

**Problem:** the shipped interface uses decomposed-parameter method names
(`renderCreateTableStatement(QualifiedName, array, array, array)`) while doc 13 §3 specifies
VO-receiving methods (`renderCreateTable(CreateTableBuilder)`). Seven per-column/constraint
render methods live only on `AbstractPlatform`, not the interface. Five duplicate DTO-vs-interface
method pairs coexist (incomplete migration). (M1/M2/M4.)

**Decision — pick one and finish it:**
- **A (recommended): ratify the shipped decomposed style.** Update doc 13 §3 to match the actual signatures. Add the seven missing per-column/constraint methods to `DdlDialectInterface` so callers don't downcast to `AbstractPlatform`. Remove the deprecated half of each duplicate pair.
- **B: migrate to VO-receiving.** Larger diff; only if the VO style is genuinely preferred. Do it in one pass, not ad hoc.

**Acceptance:** interface and doc 13 §3 agree; no duplicate render-method pairs; per-column
methods are callable through the interface. `make build` green.

---

## 7.3 Small DDL correctness (fold in)

- **SQLite `renderCreateSchemaStatement` (Audit 04 §3.2, M3):** SQLite has no `CREATE SCHEMA`; `toSql()` currently emits SQL SQLite rejects. Add `SqlitePlatform::renderCreateSchemaStatement()` that `throw $this->unsupported(Capability::Scheme)` so the preview path is honest.
- **Capability throw-timing consistency (Audit 04 §6, L1):** most builders throw at `toSql()` (platform renderer); `CreateSchemaBuilder` alone throws at `execute()`. Standardize on the platform-renderer `toSql()`-time throw for all capability-gated builders (single, predictable location), or add `execute()`-time guards uniformly. Pick one.

**Acceptance:** SQLite `CREATE SCHEMA` preview throws instead of emitting invalid SQL; capability
failures surface at a single consistent point.

---

## Phase 7 exit criteria

- Every dropped builder group is built-with-tests or explicitly deferred in doc 04 + roadmap.
- `DdlDialectInterface` reconciled with doc 13; no duplicate pairs; per-column methods on the interface.
- SQLite schema preview honest; capability-throw timing consistent.
- `make build`/`make test` green.
