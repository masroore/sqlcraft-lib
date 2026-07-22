# Phase 2 — Foundation contracts & missing primitives

> Depends on: nothing. Can run parallel to Phase 1.
> Release-blocking: yes — unblocks Phases 3–6.
> Closes audit findings: 01 H-1/H-2/H-3/H-4/H-5/H-6/M-3; 02 finding 2 (Credential VO half); 07 §1.2.3.

This phase adds the missing contracts and small primitives that everything downstream
depends on. It is deliberately low-risk: mostly new interfaces and value objects, no
behavioral rewiring. Building these first means Phases 3–6 have real types to implement
against instead of inventing them ad hoc.

---

## 2.1 Missing boundary contracts

The Contracts layer has empty directories where the design promised interfaces. Concretes
exist (e.g. `SchemaManager`) with no port, breaking DIP and making them un-mockable.

**Work — create in `src/Contracts/`:**

| Interface | Path | Minimum surface | Audit |
|---|---|---|---|
| `SchemaInspectorInterface` | `Contracts/Schema/` | `compare()`, `describeDiff()`; plus `SchemaManagerInterface` for the concrete `SchemaManager` | 01 H-2 |
| `QueryBuilderInterface` | `Contracts/Query/` | `from()`, `where()`, `orderBy()`, `paginate()`, `toSql()` | 01 H-4 |
| `SecurityGuardInterface` | `Contracts/Security/` | `can(string $action, QualifiedName $object): bool`, `require(...)` | 01 H-3 / 07 §1.2.1 (impl in Phase 4) |

Move `PaginatorInterface` from `src/Query/` to `src/Contracts/Query/` (Audit 05 §7a) while
here. Keep `QueryHistoryEntry` — it is a concrete DTO misfiled in `Contracts/Execution/`;
move to `src/DTO/` (Audit 05 §7d).

**Acceptance:** every `src/Contracts/*` dir that the design names has its interface;
`SchemaManager` implements `SchemaManagerInterface`; Deptrac still green.

---

## 2.2 `LazyCollection`

**Problem:** doc 07 §4 promises `LazyCollection` wrapping a `\Closure` producer,
materializing on first iteration, returned by services for large result sets (thousands of
tables). It does not exist anywhere; every collection is eager. The performance contract is
undeliverable. (Audit 01 H-1, High.)

**Work:**
1. `src/Collections/LazyCollection.php` implementing `IteratorAggregate` + `Countable`, backed by a `\Closure(): iterable` producer, materializing once and caching.
2. Provide the lazy path where it matters most first: `SchemaManager::getTables()` / `getAllColumns()` on large schemas. Do not retrofit every collection — only those with unbounded cardinality.

**Acceptance:** a service can return a `LazyCollection` that issues zero queries until
iterated; a test asserts the producer is not called at construction. `ponytail:` note —
only wire lazy where cardinality is genuinely unbounded; eager is fine for bounded sets.

---

## 2.3 `QueryTimeoutException` + `Capability::QueryTimeout`

**Problem:** doc 02 §8 hierarchy includes `QueryException → QueryTimeoutException`; doc 04 §1
names `Capability::QueryTimeout`. Neither exists. `queryWithTimeout()` is implemented but a
timed-out query surfaces as an unclassified `QueryException` — callers can't distinguish a
timeout from a syntax error. (Audit 01 H-6, High.)

**Work:**
1. `src/Exceptions/QueryTimeoutException.php extends QueryException` (final).
2. `case QueryTimeout` in `Capability` enum, matching doc 09 naming style.
3. In `QueryExecutor::queryWithTimeout()` / platform `wrapWithTimeout()`, translate the driver's timeout error (per-engine SQLSTATE) into `QueryTimeoutException`.

**Acceptance:** a query exceeding the timeout throws `QueryTimeoutException`, catchable
distinctly from `SyntaxErrorException`. Capability gate present where timeout is unsupported.

---

## 2.4 `Utilities` module

**Problem:** `deptrac.yaml` declares a `Utilities` layer pointing at `src/Utilities/.*`,
which **does not exist** — a silent no-op collector. doc 07 §10 names `PaginationCalculator`
and `IdentifierSanitizer`. The sanitizer matters: `Identifier` validates post-construction,
but there's no pre-VO layer stripping dangerous characters from user-supplied names.
(Audit 01 M-3.)

**Decision — pick one:**
- **A (recommended): build it.** Create `src/Utilities/PaginationCalculator.php` and `src/Utilities/IdentifierSanitizer.php` with tests. The deptrac layer becomes real.
- **B: drop the layer.** If both utilities are genuinely covered elsewhere (`PaginationParams` already does offset/limit math; identifier safety is arguably the VO's job), delete the `Utilities` layer from `deptrac.yaml` instead (Phase 8 §config). Do **not** leave a layer pointing at a nonexistent dir.

Recommend A only if `IdentifierSanitizer` fills a real pre-validation need; otherwise B.
Resolve the dead deptrac layer either way.

**Acceptance:** `src/Utilities/` exists with tested classes **or** the layer is removed from
`deptrac.yaml`. No layer references a nonexistent directory.

---

## 2.5 Enum / exception hygiene (low-risk, fold in here)

- **`Capability` enum vs doc 04 (Audit 01 H-5):** doc 04 names ~20 cases (`DatabaseRename`, `TableMove`, `FullTextIndex`, etc.) absent from the enum. This is a **doc-04-vs-doc-09 reconciliation**, not necessarily 20 new cases. Decide per feature: add the case (if the feature is in scope for a later phase — e.g. `DatabaseRename`/`TableMove` map to Phase 7 builders) or update doc 04 to reference an existing case. Add only the cases whose features this plan actually builds; defer the rest with a doc note. Handled jointly with Phase 7 (builders) and Phase 8 (doc reconciliation).
- **`final` on leaf exceptions (Audit 01 M-1):** mark `QueryException` and `ConstraintViolationException` `final` — wait: `QueryTimeoutException` (2.3) extends `QueryException`, so `QueryException` must stay non-final. Mark only genuine leaves final; document `QueryException`/`ConstraintViolationException` as intentional bases if they have subtypes. Re-audit the hierarchy before flipping keywords.
- **`readonly class` on collections + `PlatformCapabilityResolver` (Audit 01 L-2/M-6):** declare `AbstractImmutableCollection`, concrete collections, and `PlatformCapabilityResolver` as `readonly class`. Mechanical; verify no subclass adds mutable state first.

**Acceptance:** enum reconciled (added-or-doc-noted, no orphan doc claims); exception
finality correct given the timeout subtype; readonly-class applied where safe. `make build`
green including Psalm/PHPStan readonly rules.

---

## Phase 2 exit criteria

- Every promised boundary contract exists in `src/Contracts/`.
- `LazyCollection` available and used on unbounded result sets.
- `QueryTimeoutException` + capability wired into the timeout path.
- `Utilities` resolved (built or layer removed) — no dead deptrac layer.
- Enum/exception/readonly hygiene reconciled with docs.
- `make build` green; downstream phases have concrete types to build against.
