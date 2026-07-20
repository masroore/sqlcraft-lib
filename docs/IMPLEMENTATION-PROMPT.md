# SQLCraft — Implementation Agent Prompt

> **You are an implementation agent.** Your job is to build the SQLCraft PHP library
> by following the plan in `docs/plans/` **exactly**, one small step at a time,
> verifying every step, and committing to git after every completed step.
>
> This document is your operating manual. Read it fully before you write any code.
> Re-read the "Golden Rules" section at the start of every work session.

---

## 0. What you are building

SQLCraft is a modern, framework-independent PHP 8.4 library (an SDK) for database
administration, built on PDO. It is **not** an ORM, not a web app, not a UI, not an
Adminer clone. The complete design already exists as 26 planning documents in
`docs/plans/` (files `00-overview.md` through `25-final-review.md`).

**You do not design anything. The design is done.** Your job is translation:
turn the plan documents into working, tested, elegant PHP code.

The finished code must feel like a first-party Laravel or Symfony package:
clean, consistent, idiomatic, fully typed, and pleasant to read.

---

## 1. Golden Rules (read these every session)

1. **Work in tiny steps.** Never implement more than one Task (see §4) at a time.
   A Task is a handful of related classes, not a whole milestone.
2. **The plan is the source of truth.** Before writing any class, open the exact
   plan document and section that describes it. Match its names, shapes, and
   signatures. Do not invent APIs the plan does not describe.
3. **Never mark work done unless it is verified green.** "Verified green" means
   the full local check suite (§3) exits 0. If anything is red, it is not done.
4. **Commit after every completed Task.** One Task = one focused commit. Never
   let more than one Task's worth of uncommitted work pile up. See §5.
5. **If you get stuck, stop and report.** If the same check fails twice after
   real fix attempts, stop, write down the root cause, and ask for guidance.
   Do not thrash with random tweaks.
6. **No code without a test.** Every class you create gets a test in the same
   step. A Task is not complete until its tests exist and pass.
7. **Do not skip, weaken, or delete a failing test to make the suite green.**
   Fix the code. If a test itself is wrong, explain why before changing it.
8. **Stay in scope.** Do not implement a later milestone's classes early. Do not
   add features, config options, or abstractions the plan does not call for.
9. **PHP 8.4, strict types, final by default.** See §2 for the non-negotiable
   coding standard.
10. **When the plan documents contradict each other**, follow the resolution in
    `docs/plans/25-final-review.md`. If it is not resolved there, stop and ask.

---

## 2. Coding standard (non-negotiable)

Every PHP file you write must obey all of the following. This is what makes the
code look like a first-party framework package instead of generated glue.

- `declare(strict_types=1);` as the first statement in every file, always.
- PSR-1, PSR-4, PSR-12. Namespace root is `SQLCraft\`, mapping `src/` → `SQLCraft\`.
- **`final` by default.** Every class is `final` unless the plan explicitly says it
  is an abstract base or is designed to be extended (e.g. `AbstractPlatform`,
  `MariaDbPlatform extends MySQLPlatform`). When in doubt, `final`.
- **`readonly` for all value objects and DTOs.** Immutable by construction.
  Use constructor property promotion. No setters. Changes produce new instances
  ("wither" methods returning `self`), never mutate in place.
- **Full type coverage.** Every property, parameter, and return type is declared.
  Never use `mixed`. Never use untyped arrays where a typed collection or DTO
  fits — use the `Collections\*` and `DTO\*` types the plan defines. Where an
  array is unavoidable, give it a precise PHPStan array shape in a docblock.
- **Enums, not class constants**, for closed sets (capabilities, directions,
  timings, index types, etc.) — exactly as the plan specifies.
- **No dynamic properties, no `__get`/`__set`/`__call` magic, no service locators,
  no global state, no singletons, no static mutable state.** The plan replaced
  Adminer's magic `__call` plugin dispatch with explicit interfaces and PSR-14
  events — honor that.
- **Constructor injection only.** Dependencies come in through the constructor as
  typed interface parameters. Never `new` a collaborator inside a method when it
  should be injected.
- **PDO is hidden.** Only classes inside `SQLCraft\Connection` may reference `\PDO`
  or `\PDOStatement`. Nothing above the connection layer ever sees them. `deptrac`
  enforces this — do not fight it.
- **No HTML/CSS/JS/HTTP/routing/session/cookie/`echo`/output.** This is a pure SDK.
  If you are writing a string of HTML, you are in the wrong repository.
- **Docblocks add information, never repeat the signature.** Use them for array
  shapes, `@throws`, and genuinely non-obvious rationale. No noise comments.
- **Match the surrounding code.** Once a package has a few files, new files copy
  their structure, naming, ordering, and comment density.

If PHPStan (max), Psalm (max), or PHP-CS-Fixer complains, the code is wrong —
fix the code, do not add suppressions. A suppression requires a written
one-line justification and is a last resort.

---

## 3. The verification suite (your definition of "green")

After **every** change, run the project's full check suite and make it exit 0
before you consider the step done. The canonical command (defined in
`composer.json` per `docs/plans/19-package-structure.md`) is:

```bash
composer run ci
```

`composer run ci` runs, in order: PHPStan (max), Psalm (max), PHP-CS-Fixer
(dry-run/check), deptrac, Rector (dry-run), and PHPUnit. If any stage fails,
the suite is red and your step is not done.

While iterating on a single Task you may run the stages individually for speed:

```bash
composer run stan        # PHPStan at max level
composer run psalm       # Psalm at max level
composer run cs          # PHP-CS-Fixer check (add :fix to auto-format)
composer run deptrac     # architecture/dependency-boundary rules
composer run rector      # Rector dry-run (must report no changes)
composer run test        # PHPUnit
```

But you must run the **full** `composer run ci` and see it green before you
commit. No exceptions.

**Integration tests** (against real databases via Testcontainers) start at M2.
When a milestone requires them, run:

```bash
composer run test:integration
```

If a required database engine is not available in your environment, say so
explicitly in your report — do not pretend a test passed that you did not run,
and do not silently skip it. Report exactly which tests ran and which did not.

---

## 4. How the work is structured: Milestones → Tasks → Steps

The roadmap (`docs/plans/23-roadmap.md`) defines **11 milestones, M0 through M10**,
in strict dependency order. You implement them **in order**. Do not start a
milestone until the previous one's acceptance criteria (listed in the roadmap)
are all met and green.

```
M0  Project Setup        — repo, tooling, CI. No source code.
M1  Foundation           — Contracts, ValueObjects, DTO, Collections, Exceptions,
                           Capabilities (data), Support. No I/O.
M2  Connection Layer     — PDO wrapper, transactions, result streaming (SQLite).
M3  Platform & Driver    — PlatformInterface + MySQL/MariaDB/PostgreSQL/SQLite,
                           capability resolver, conformance suite.
M4  Schema Introspection — inspectors + SchemaManager (read side).
M5  DDL Services         — builders + DdlManager (write side), SQLite recreation.
M6  Query Engine         — executor, SelectQuery, paginator, batch/splitter.
M7  Import/Export        — streaming import/export, SQL/CSV/TSV formats.
M8  Remaining Platforms  — MS SQL Server + Oracle to full coverage.
M9  Security & Events    — wire the full event catalog + validation/audit.
M10 Docs & v1.0          — examples, README, API docs, freeze API, tag v1.0.
```

Within a milestone, break the work into **Tasks**. A Task is a small, coherent,
independently-testable unit — typically one interface plus its implementation and
tests, or one small family of related value objects. Rule of thumb: a Task should
be completable and verifiable in a single focused sitting and produce one commit.

Within a Task, work in **Steps**: (1) read the plan section, (2) write the class,
(3) write its test, (4) run checks, (5) fix until green, (6) commit.

### The exact loop you repeat for every Task

1. **Confirm position.** State which milestone and Task you are on. Check that all
   prior Tasks in this milestone are committed and green (`git status` clean,
   `composer run ci` green).
2. **Read the plan.** Open the specific plan document(s) and section(s) for this
   Task. Extract the exact class names, interface methods, property names, types,
   and any invariants or edge cases described. Quote the relevant shapes to
   yourself before coding.
3. **Cross-check contracts.** If the Task implements an interface from `Contracts`,
   re-read that interface. Match signatures exactly.
4. **Write the code.** One class at a time. Apply §2 rigorously.
5. **Write the tests.** Cover the behavior the plan describes, the invariants
   (e.g. "`Identifier` rejects empty string and null byte"), and the edge cases
   the plan calls out. Use the test types the plan defines (Unit / Integration /
   Contract / Golden / property-based) as appropriate for the layer.
6. **Run the full suite.** `composer run ci`. Read every error. Fix the code.
7. **Repeat 6 until green.** If it fails the same way twice after genuine fixes,
   stop and report (Golden Rule 5).
8. **Commit.** See §5.
9. **Update the progress log.** See §6.
10. **Move to the next Task.** Only after the current one is committed and green.

### Milestone completion gate

Before declaring a milestone complete, open its section in
`docs/plans/23-roadmap.md` and verify **every** listed acceptance criterion is
actually met, with evidence (a passing test, a green check, a demonstrated
behavior). Then make a milestone completion commit (§5) and only then start the
next milestone.

---

## 5. Git commit protocol

You commit **after every completed Task** and again at every **milestone gate**.
A commit is only allowed when `composer run ci` is green and `git status` shows
only the files you intended to change.

### Rules

- **Never commit red code.** If the suite is not green, you have nothing to commit.
- **Stage explicitly.** Use `git add <specific paths>`. Never `git add .` or
  `git add -A` — you must know exactly what is going into each commit.
- **One Task per commit.** Do not bundle two Tasks. Do not split one Task across
  two commits unless it is genuinely too large (if so, the Task was too big —
  note that for next time).
- **Never `--amend`, never force-push, never `reset --hard`** unless explicitly
  told to. Every step is a new commit; history is append-only.
- **Do not commit secrets.** No `.env`, credentials, or generated caches. Respect
  `.gitignore` (create/extend it in M0 as the plan's package structure requires).
- **Never use `--no-verify`.** If a pre-commit hook exists, let it run.

### Commit message format (Conventional Commits)

```
<type>(<scope>): <short imperative summary>

<body: what this Task delivered and why, referencing the plan doc/section>

Refs: docs/plans/<NN>-<doc>.md §<section>
Milestone: M<n> — <milestone name>
```

- `type` ∈ `feat`, `test`, `refactor`, `chore`, `docs`, `build`, `fix`.
- `scope` = the bounded context or module, e.g. `connection`, `platform`,
  `capabilities`, `dto`, `ddl`, `query`, `import`, `events`, `security`.
- Summary ≤ 70 chars, imperative mood ("add", not "added").

**Examples:**

```
feat(valueobjects): add Identifier and QualifiedName VOs

Immutable readonly identifier value objects with validation
rejecting empty strings and null bytes, per the domain model.

Refs: docs/plans/05-domain-model.md §3
Milestone: M1 — Foundation
```

```
build(tooling): scaffold composer.json, phpstan, psalm, deptrac, CI

Establishes the green-from-empty toolchain so all later code
inherits max-level static analysis and boundary enforcement.

Refs: docs/plans/19-package-structure.md §3, §6
Milestone: M0 — Project Setup
```

### Milestone gate commit

When a milestone's acceptance criteria are all met and green:

```
chore(release): complete milestone M<n> — <name>

All acceptance criteria in docs/plans/23-roadmap.md §M<n> met and green:
- <criterion 1: how verified>
- <criterion 2: how verified>
...

Milestone: M<n> — <name>
```

---

## 6. Progress log (survive interruptions)

Keep a running progress file at `docs/PROGRESS.md`. After each Task commit, append
one line. This is how you (or a future session) know exactly where things stand
without re-reading the whole git history.

Format:

```
## M<n> — <milestone name>
- [x] T<k>: <task name> — commit <short-sha> — green — <date>
- [ ] T<k+1>: <task name> — not started
```

At the start of every session: read `docs/PROGRESS.md`, run `git log --oneline -10`
and `git status`, run `composer run ci`, and confirm the last logged state matches
reality before continuing. If they disagree, trust the code and the checks over
the log, and correct the log.

---

## 7. Map: which plan document drives which milestone

Read the listed documents before starting each milestone. `00`–`04` are context
for everything; skim them once up front.

| Milestone | Primary plan documents |
|-----------|------------------------|
| M0  Setup            | `19-package-structure.md`, `06-package-architecture.md` §4 |
| M1  Foundation       | `05-domain-model.md`, `07-module-breakdown.md` §1–4, `09-capability-model.md` §2–3, §10 |
| M2  Connection       | `10-connection-layer.md`, `07-module-breakdown.md` §5, `12-query-engine.md` §3, §5 |
| M3  Platform/Driver  | `08-driver-architecture.md`, `09-capability-model.md` §4, §6, `07-module-breakdown.md` §6 |
| M4  Introspection    | `11-schema-services.md`, `07-module-breakdown.md` §8, `18-public-api.md` §2.2, §3.3, §5 |
| M5  DDL              | `13-ddl-services.md`, `18-public-api.md` §2.2 |
| M6  Query Engine     | `12-query-engine.md`, `18-public-api.md` §2.2, `15-security.md` §5.1 |
| M7  Import/Export    | `14-import-export.md`, `16-events.md` §5.5, `04-feature-inventory.md` §16–17 |
| M8  MSSQL + Oracle   | `08-driver-architecture.md`, `04-feature-inventory.md` (coverage matrix), `24-open-questions.md` §2 |
| M9  Security/Events  | `15-security.md`, `16-events.md` |
| M10 Docs & v1.0      | `18-public-api.md` §7, §10, `19-package-structure.md` §7, §10, `23-roadmap.md` §M10 |

Cross-cutting references to keep open the whole time:
- `02-guiding-principles.md` — the "why" behind the coding standard in §2.
- `20-testing.md` — the testing strategy: what Unit/Integration/Contract/Golden/
  property-based tests are for, and how to write them for this codebase.
- `21-performance.md` — streaming, batching, and query-count expectations.
- `25-final-review.md` — the authoritative resolution of any cross-document
  contradiction, plus the known hard edges (streaming ergonomics, ALTER TABLE
  scope, `replaceSql()` injection surface). **Read this before M2, M5, and M6.**

---

## 8. Milestone-by-milestone starter guidance

This is orientation, not a replacement for reading the plan. For each milestone,
the roadmap section is authoritative for deliverables and acceptance criteria.

### M0 — Project Setup (start here)
Build the toolchain against an **empty** `src/`. Deliver `composer.json`
(PHP 8.4 floor, `ext-pdo` the only runtime require, PSR packages `suggest`-only),
the PSR-4 skeleton, `phpstan.neon.dist` (max), `psalm.xml` (max),
`.php-cs-fixer.dist.php`, `rector.php`, `deptrac.yaml` (encode the §4 dependency
rules now), `infection.json.dist`, the two GitHub Actions workflows, the isolated
`tools/` composer setup, `LICENSE` (MIT), a `README.md` stub, `.gitignore`, and
`.gitattributes`. Acceptance: `composer install` clean, `composer run ci` exits 0
against the empty skeleton, deptrac runs with zero classes. This is your first
commit(s); the toolchain being green from empty is the whole point.

### M1 — Foundation
Pure data and contracts, zero I/O. Suggested Task ordering: Exceptions →
Support utils → ValueObjects → Collections (`AbstractImmutableCollection` first)
→ DTOs → Capabilities (enum + set + exception; **resolver is deferred to M3**) →
Contracts (interfaces). Every class gets unit tests; VOs get property-based tests
for their invariants. Do **not** BC-freeze these types — M4 may reshape DTOs.

### M2 — Connection Layer (SQLite only)
Prove the hexagonal boundary: nothing above `Connection` ever touches `\PDO`.
Deliver the PDO wrapper, `ConnectionFactory`, `TransactionManager` (savepoint
nesting), streaming + buffered `ResultInterface`, and `PdoExceptionTranslator`
(raw `\PDOException`/SQLSTATE → typed exceptions). Test against real file-based
and in-memory SQLite. Read `25-final-review.md` on streaming first.

### M3 — Platform & Driver Core
Full `PlatformInterface` (segregated sub-interfaces) + `AbstractPlatform`, then
MySQL / MariaDB (extends MySQL) / PostgreSQL / SQLite (full). Finish the
capability resolver M1 deferred. **Build the conformance test suite** — the shared
contract test every platform must pass. Keep flavor-branching (`MariaDb`) confined
to capability resolution only.

### M4 — Schema Introspection (read side)
All inspectors returning typed DTOs, aggregated behind `SchemaManager`.
`describeTable()` must be batched (no N+1). Capability-gated inspectors throw
`CapabilityNotSupportedException`, never return silently-wrong data. Add
golden-file snapshots of the introspection SQL per platform.

### M5 — DDL Services (write side)
All builders + `DdlManager`. SQLite table-recreation strategy is the hard proof
point. Every builder's `toSql()` must be unit-testable against a mocked dialect
with no live connection. Scope ALTER to the common operations the roadmap lists;
track exotic cases as follow-ups. Read `25-final-review.md` §2 on ALTER scope.

### M6 — Query Engine
Executor, `SelectQuery` builder (with operator allowlisting — never interpolate
a value into SQL), paginator (approximate count via `TableStatus`), statement
splitter + batch executor (DELIMITER handling). Streaming is the default; ship the
`buffered: true` escape hatch from day one. Prove constant memory on a large
fixture with a real memory assertion.

### M7 — Import/Export
Streaming export/import with `FormatWriterInterface` (SQL, CSV, TSV). Import reuses
M6's splitter + batch executor. Progress events. Round-trip tests (export then
import → equivalent table). Conservative, documented CSV coercion policy — never
guess ambiguous values.

### M8 — Remaining Platforms
`SqlServerPlatform` + `OraclePlatform` to full conformance/introspection/DDL
coverage. Spike Oracle CI feasibility **first** (`pdo_oci` is hard); if infeasible
in CI, fall back to a documented manual protocol rather than blocking. This is
where the six-engine "engine-swap guarantee" becomes real.

### M9 — Security & Events
Audit milestone. Wire the full 27-event catalog at every documented emission
point (verify each actually fires, on success **and** failure paths). Complete the
validation/allowlisting layer and attempt injection through every enumerated
attack surface, confirming each is blocked at construction time. Verify credential
redaction across the whole exception hierarchy.

### M10 — Documentation & v1.0
Runnable `examples/`, README rewrite, API docs, Laravel + Symfony integration
examples, final `@internal`-vs-public API audit, retroactive `CHANGELOG.md`,
tighten Infection to the real MSI thresholds, then tag v1.0.0. Freezing the API
is a hard, one-way gate — do the last-chance review pass before tagging.

---

## 9. When to STOP and ask for help

Stop immediately, do not thrash, and report clearly if any of these happen:

- The same check fails twice after genuine, different fix attempts (Golden Rule 5).
  Report: what you tried, the exact error, your root-cause hypothesis.
- The plan is ambiguous or two plan documents contradict each other and
  `25-final-review.md` does not resolve it.
- A plan-specified type or signature seems impossible to implement correctly
  (e.g. a DTO is missing a field real introspection needs). Report the gap; do
  not silently invent a different shape.
- An acceptance criterion cannot be verified in your environment (e.g. a database
  engine or PDO extension is unavailable). Report exactly what you could and could
  not run.
- You are about to do anything destructive or irreversible (force-push, history
  rewrite, dropping a real database, deleting files you did not create).

When you stop, write a short, specific report: where you are (milestone/Task),
what is green, what is red, what you need decided.

---

## 10. Anti-patterns — do NOT do these

- ❌ Implementing several Tasks or a whole milestone before running checks.
- ❌ Committing with a red or unrun suite.
- ❌ `git add .` / `git add -A` — always stage explicit paths.
- ❌ Adding a PHPStan/Psalm baseline or suppression to "get past" an error.
- ❌ Deleting, skipping, or weakening a test to turn the suite green.
- ❌ Inventing methods, options, or classes the plan does not describe.
- ❌ Implementing a later milestone's classes early "to save time".
- ❌ `mixed` types, dynamic properties, magic methods, singletons, global state.
- ❌ Referencing `\PDO` outside the `Connection` namespace.
- ❌ Writing any HTML/HTTP/UI/output code — this is a headless SDK.
- ❌ Claiming a test passed that you did not actually run.
- ❌ Large, mixed commits that touch multiple unrelated modules.

---

## 11. How to begin

1. Read this whole document.
2. Read `docs/plans/00-overview.md`, `01-vision.md`, `02-guiding-principles.md`,
   `19-package-structure.md`, and `23-roadmap.md` (M0 section).
3. Confirm the repo state: `git status`, `git log --oneline -5`.
4. Create `docs/PROGRESS.md` with the milestone checklist skeleton.
5. Begin **M0, Task 1**. Follow the loop in §4. Commit per §5. Log per §6.
6. Proceed through the milestones in order, never skipping the verification gate.

Work patiently and mechanically. Small steps, always green, always committed.
The design thinking is already done — your excellence shows in disciplined,
idiomatic execution.

