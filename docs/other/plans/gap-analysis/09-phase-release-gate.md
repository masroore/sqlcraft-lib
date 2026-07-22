# Phase 9 — Release gate: mutation coverage & v1.0 tag

> Depends on: Phases 1–8.
> Release-blocking: **final gate.**
> Closes: M10 T3 (the only tracker line honestly describing a blocker) + the real blockers this plan surfaces.

`PROGRESS.md` frames v1.0 as blocked solely on the Infection MSI threshold (57% actual vs 80%
min-MSI / 90% covered-MSI in `composer.json`). The audit's core finding: **that is the least of
it.** Mutation coverage is meaningless on code that omits its promised features. This phase runs
last, after the correctness and completeness gaps are closed.

---

## 9.1 Precondition: gaps closed first

Do not chase MSI until these land:
- Phase 1 (correctness) complete — green milestones actually true.
- Phase 4 (security) + Phase 5 (DML) complete — the M9/M6 claims backed by real code.
- Phase 7 decisions made — no builder group in the silent promised-but-absent state.
- Phase 8 hygiene done — docs and tracker honest.

Raising mutation coverage before this produces a well-tested but incomplete v1.0 — the exact
trap the audit calls out.

---

## 9.2 Mutation coverage to threshold

**Target:** `--min-msi=80 --min-covered-msi=90` (from `composer.json`). Current: 57% / 75%.

**Work:**
1. Run `make test` + Infection; collect the escaped-mutant report.
2. The new code from Phases 1–7 must ship with tests that kill mutants as it lands — do not defer test-writing to this phase for new features. This phase closes the gap on **pre-existing** code plus any residual escapes.
3. Prioritize escaped mutants in security (Phase 4), DML rendering (Phase 5), and the splitter state machine (Phase 1 §1.6) — highest-risk logic.
4. Where a mutant is genuinely equivalent, mark it with Infection's ignore annotation + a one-line reason; do not inflate coverage with assertion-free tests.

**Acceptance:** MSI ≥ 80%, covered-MSI ≥ 90%, honestly (no assertion-free padding, equivalents
documented).

---

## 9.3 Full verification sweep

1. `make build` — PHPStan, Psalm, Deptrac, php-cs-fixer, Rector all green.
2. `make test` — unit + integration (engine-gated tests run against the five supported engines: MySQL, MariaDB, PostgreSQL, SQLite, SQL Server).
3. Golden tests cover all five engines including the new `sqlserver` introspection fixture (Phase 1 §1.1).
4. Deptrac boundary honest (Phase 8 §8.4) — no Contracts→adapter leak, no dead layer.
5. Re-run the v2 audit's spot checks: every declared cache/flag/event has ≥1 caller ("seam liveness"); no capability advertised-but-throwing.

---

## 9.4 Seam-liveness regression test (prevent recurrence)

The audit's most valuable cross-cutting finding was "defined but never wired" seams
(cache invalidation, dead export flags, orphan DTOs). Add a lightweight architectural test that
fails when a declared seam has zero callers:
- every `MetadataCacheInterface` invalidation method has a caller,
- every `DumpOptions` flag is read by export code,
- every `Capability` advertised by a platform has a non-throwing render path (or is intentionally gated),
- every event class is dispatched from ≥1 path.

This is the guard that would have caught the whole class of bugs this plan fixes.

**Acceptance:** the seam-liveness test passes and is wired into `make test` so regressions fail CI.

---

## 9.5 Tag v1.0.0

Only after 9.2–9.4 green:
1. Update `PROGRESS.md` M10 T3 to green with the release commit.
2. Changelog reflects the gap-closure work (not just "raised MSI").
3. Tag `v1.0.0`.

---

## Phase 9 exit criteria

- All prior phases complete; green milestones are true, not just checked.
- MSI ≥ 80% / covered-MSI ≥ 90%, honestly.
- `make build`/`make test` green across five engines; goldens cover all five.
- Seam-liveness test guards against the "defined-but-unwired" regression class.
- `v1.0.0` tagged with an honest changelog.
