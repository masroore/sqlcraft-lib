# Plan-vs-Implementation Conformance Audit — Prompt

> Reusable audit prompt. Paste into an agent session (or wire up as a slash command) to
> reproduce the `docs/audit/v1/`-style conformance audit. Placeholders in `{{…}}`.
> For this repo: `{{PROJECT}}` = SQLCraft, `{{ROOT}}` = repo root, `{{DOCS_DIR}}` = `docs/plans/`,
> `{{SRC_DIR}}` = `src/`, `{{TESTS_DIR}}` = `tests/`, `{{PROGRESS_FILE}}` = `docs/PROGRESS.md`,
> `{{OUT_DIR}}` = `docs/audit/vN/`.

---

You are auditing {{PROJECT}} — {{ONE_LINE_DESCRIPTION}}. Repo root: {{ROOT}}.
This is a READ-ONLY analysis until Phase 5 output is written. Do not modify source code.

**Inputs**
- Design documents: `{{DOCS_DIR}}` (e.g. `docs/plans/00–25`)
- Implementation: `{{SRC_DIR}}` (e.g. `src/`), tests: `{{TESTS_DIR}}`
- Progress tracker: `{{PROGRESS_FILE}}` (e.g. `docs/PROGRESS.md`)
- Output folder: `{{OUT_DIR}}` (e.g. `docs/audit/v2/`)

---

## Phase 1 — Orientation (do this yourself, not via sub-agents)

1. Pin the baseline: `git rev-parse --short HEAD`, branch, working-tree cleanliness. Every output doc cites this in its header.
2. List all plan docs; read the overview, roadmap, and progress tracker fully. Build a mental map of:
   - what each milestone **claims as done** (green), **blocked**, or **deferred**
   - the project's own stated scope promises (README, overview diagrams, feature inventory)
3. List the source tree (2 levels deep) so you can cluster plan docs ↔ source dirs.

**Critical distinction to carry throughout:** an item the plan *explicitly defers* (e.g. "v1.1", "interface seam only") is NOT a gap — flag it as an *acknowledged deferral*. An item promised in one plan doc but *silently absent* from the detailed design and code is a *silent drop* — that IS a finding.

## Phase 2 — Parallel area audits (fan out to sub-agents)

Cluster plan docs with their source directories into 5–9 disjoint areas. Each area must map
plan doc(s) → concrete `src/` dirs → (optionally) test dirs. Launch one sub-agent per area,
all in a single message, using this briefing template:

> You are auditing {{PROJECT}} ({{ONE_LINE_DESCRIPTION}}). Repo root: {{ROOT}}. READ-ONLY.
>
> **Read fully:** {{PLAN_DOC_PATHS}}
> **Explore:** {{SRC_DIRS}}, plus relevant contracts/interfaces and tests.
>
> Compare design vs implementation and report:
> - **Gaps** — planned but missing/incomplete
> - **Drift** — implemented differently than planned (note if the drift is arguably an improvement)
> - **Extras** — implemented but not in the plan
> - **Faithful** — what matches (mandatory section; cite it — absence of this section means you only looked for problems)
>
> **Evidence rules (non-negotiable):**
> - Every finding cites plan doc + section AND concrete file path(s)/line(s).
> - Before declaring something "missing", verify with grep/glob across the whole tree — absence claims must be proven, not assumed.
> - Check for **dead options**: fields/flags/parameters defined on DTOs or option objects that no code path ever reads. Grep each option name's usages.
> - Check **stated invariants**: plans often say "X never calls Y directly", "PDO never surfaces past Z", "builders route through W". Test each one against the code.
> - Check for **orphans**: classes/DTOs defined but referenced nowhere.
> - Check **plan-internal contradictions** (overview diagram vs detailed spec). When they conflict, identify which doc is authoritative (detailed spec usually wins) and judge the code against that — record the contradiction itself as a finding.
> - Check configs/CI for **stale artifacts** referencing dropped scope (e.g. matrix entries for a removed engine, deps for an unbuilt feature).
> - Distinguish **acknowledged deferrals** (plan itself defers) from **silent drops** (promised upstream, quietly absent downstream).
>
> **Severity:** CRITICAL = core planned artifact missing or code violates a stated invariant / produces wrong behavior; MODERATE = planned feature absent or behavioral divergence; MINOR = naming, placement, cosmetic.
>
> Return: `## <Area>` → `### Gaps` / `### Drift` / `### Extras` / `### Faithful` / `### Summary (2–3 sentences)`. Dense, specific, no filler.

## Phase 3 — Aggregate (do this yourself)

Merge area reports into one picture. Additionally sweep for **cross-cutting patterns** single-area agents miss:
- Promises repeated across several docs (overview, domain model, module breakdown, feature inventory) that the *detailed* design doc silently dropped — these are the highest-value findings.
- The same dead option or unwired seam appearing in multiple areas.
- Doc-vs-doc label drift (e.g. project README/QWEN.md describing a directory's purpose differently than both plan and code).
- Progress-tracker hygiene (duplicate/conflicting task lines, "commit pending" placeholders).

## Phase 4 — Fix plan

For every CRITICAL and MODERATE finding, produce an action entry:

| Field | Requirement |
|---|---|
| Action | One of: **implement** (build what was promised), **wire** (connect existing code), **remove** (delete dead option/artifact), **amend-docs** (code is right or scope changed — update plan/README to match reality), **decide** (genuine scope call — state the decision needed and the options) |
| Files | Concrete paths touched |
| Severity | From the finding |
| Size | S / M / L |
| Verify | How you'll know it's fixed (test name, command, grep that should now match/not match) |
| Depends on | Other action IDs, if any |

Then organize actions into **workstreams** ordered by dependency, e.g.:
1. Scope honesty (amend-docs/decide items — cheap, unblocks everything else)
2. Correctness hazards (wrong SQL, data loss, silent misbehavior)
3. Wire-or-remove dead options
4. Missing subsystems (grouped by milestone-worthiness)
5. Release gate (mutation/coverage/CI)

**Rule:** prefer *amend-docs* whenever the code's approach is defensible and the plan is merely stale — do not reflexively "fix the code to match the plan". Flag `decide` items prominently; they are for humans, not agents.

## Phase 5 — Persist

Write the report to `{{OUT_DIR}}` as numbered markdown mirroring the plan docs' own header
convention (title, status, audit date, baseline commit, plans reviewed, dirs reviewed):

- `00-summary.md` — verdict, critical-gaps table, condensed moderate gaps, drift patterns, faithfulness highlights, open questions, workstream plan, index of area docs
- `01..NN-<area>.md` — one per audited area (full findings)
- `NN-fix-plan.md` — the action table + workstreams (or fold into 00 if small)

Cross-reference overlapping findings between docs. End your chat response with a short summary
and the list of files written.

---

## Operator notes (do not include in the prompt itself)

- **The fan-out is the mechanism.** One agent cannot hold 26 plan docs + 19 source dirs in
  context with equal depth. Disjoint clusters (each ~2–4 plan docs ↔ 2–4 src dirs) keep every
  area audit deep; aggregation is where cross-cutting findings surface (e.g. "promised in four
  docs, silently dropped in the fifth").
- **The `Faithful` section and the deferral distinction keep it honest.** Without them, audits
  skew toward false alarms — e.g. "missing plugin system" when the plan explicitly rejects a
  Plugin class.
- **Two audits may need to be done directly** if sub-agent infrastructure is unavailable; the
  area briefing template works unchanged as a self-instruction for sequential execution.
- **Rerun cadence:** after each milestone gate, or before tagging a release. Diff against the
  previous audit folder to track finding burn-down.
