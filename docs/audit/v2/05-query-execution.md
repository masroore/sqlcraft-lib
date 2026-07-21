# Query Engine & Execution Audit

> Audited: 2026-07-21
> Auditor: automated read-only audit agent
> Repo root: /Users/masroor/projects/adminer-ng/sqlcraft
> Plan docs reviewed: 12-query-engine.md, 07-module-breakdown.md §9, 18-public-api.md (QueryManager), 21-performance.md, 04-feature-inventory.md §14-15
> 11-query-service.md: does not exist

---

## 1. Promised Classes — Present vs Missing

| Class | Status | Location |
|---|---|---|
| `QueryExecutor` | **Present** | `src/Execution/QueryExecutor.php` |
| `QueryExecutorInterface` | **Present** | `src/Contracts/Execution/QueryExecutorInterface.php` |
| `StatementSplitter` | **Present** | `src/Query/StatementSplitter.php` *(wrong namespace — see §7)* |
| `StatementSplitterInterface` | **Present** | `src/Contracts/Execution/StatementSplitterInterface.php` |
| `BatchExecutor` | **Present** | `src/Execution/BatchExecutor.php` |
| `BatchExecutorInterface` | **Present** | `src/Contracts/Execution/BatchExecutorInterface.php` |
| `SelectQuery` | **Present** | `src/Query/SelectQuery.php` |
| `SelectQueryRenderer` | **Present** | `src/Query/SelectQueryRenderer.php` |
| `WhereCondition` | **Present** | `src/Query/WhereCondition.php` |
| `OrderByClause` | **Present** | `src/Query/OrderByClause.php` |
| `ColumnSelection` | **Present** | `src/Query/ColumnSelection.php` |
| `Paginator` | **Present** | `src/Query/Paginator.php` |
| `PaginationParams` | **Present** | `src/Query/PaginationParams.php` |
| `Page` | **Present** | `src/Query/Page.php` |
| `PaginatorInterface` | **Misplaced** | `src/Query/PaginatorInterface.php` (should be in `src/Contracts/Query/`) |
| `ExplainService` | **Present** | `src/Execution/ExplainService.php` |
| `ExplainServiceInterface` | **Present** | `src/Contracts/Execution/ExplainServiceInterface.php` |
| `WarningsProvider` | **Present** | `src/Execution/WarningsProvider.php` |
| `WarningsProviderInterface` | **Present** | `src/Contracts/Execution/WarningsProviderInterface.php` |
| `NullQueryHistory` | **Present** | `src/Execution/NullQueryHistory.php` |
| `InMemoryQueryHistory` | **Present** | `src/Execution/InMemoryQueryHistory.php` |
| `CallbackQueryHistory` | **Present** | `src/Execution/CallbackQueryHistory.php` |
| `QueryHistoryInterface` | **Present** | `src/Contracts/Execution/QueryHistoryInterface.php` |
| `QueryHistoryEntry` | **Present** | `src/Contracts/Execution/QueryHistoryEntry.php` |
| `QueryManager` | **Present** | `src/Execution/QueryManager.php` |
| `StreamingResult` | **Present** | `src/Connection/Result/StreamingResult.php` |
| `TransactionManager` | **Present** | `src/Connection/TransactionManager.php` *(wrong namespace — see §7)* |
| `TransactionManagerInterface` | **Present** | `src/Contracts/Execution/TransactionManagerInterface.php` |
| `InsertQuery` | **MISSING** | not found anywhere in `src/` |
| `UpdateQuery` | **MISSING** | not found anywhere in `src/` |
| `DeleteQuery` | **MISSING** | not found anywhere in `src/` |

---

## 2. [HIGH] INSERT/UPDATE/DELETE Builder Gap

**Promise:** `04-feature-inventory.md` §14 lists "Row insert", "Row update", "Row delete", and "Row clone/duplicate" as baseline features (no capability gate) owned by the Query module. `07-module-breakdown.md` §9 states the Query module provides a "Fluent query builder returning SQL strings (SELECT/INSERT/UPDATE/DELETE)." `18-public-api.md` §3.6 shows `$mysql->query()` as the entry point into this builder surface.

**Reality:** The Query module contains only SELECT-oriented classes: `SelectQuery`, `SelectQueryRenderer`, `Paginator`, `WhereCondition`, `OrderByClause`, `ColumnSelection`. There are no `InsertQuery`, `UpdateQuery`, or `DeleteQuery` classes anywhere in `src/`. DML write operations must currently be performed via `QueryManager::execute()` with hand-written raw SQL strings — forfeiting all type safety, parameter validation, and platform-aware SQL generation the design promises.

**Impact:** The browse/edit data workflow — the primary use case for an admin tool — is half-implemented. Reading rows (SELECT + paginate) works; writing rows does not use a typed builder. Any consumer performing INSERT/UPDATE/DELETE must construct raw SQL, which defeats the stated "safe execution by default" goal from `12-query-engine.md` §1.

**1-line fix:** Implement `InsertQuery`, `UpdateQuery`, `DeleteQuery` VOs and corresponding renderers in `src/Query/`, following the `SelectQuery`/`SelectQueryRenderer` pattern already established.

---

## 3. [MEDIUM] FK Navigation and Cross-Table Search

**Promise:** `04-feature-inventory.md` §14 lists "Foreign key navigation (follow FK to related row)" and "Backward keys (reverse FK — 'referenced by')" as baseline features (no capability gate) owned by the Query module. `BackwardKeyMeta` VO is promised in `07-module-breakdown.md` §3. Cross-table search is promised under `Capability::CrossTableSearch`.

**Reality:**
- `BackwardKeyMeta` DTO exists at `src/DTO/BackwardKeyMeta.php`.
- No Query-layer service, method, or class uses `BackwardKeyMeta` for navigation.
- No FK-navigation entry point exists in `QueryManager` or `Paginator`.
- No cross-table search service or fan-out logic exists anywhere in `src/Query/` or `src/Execution/`.
- `grep` of `FK`, `ForeignKey`, `foreign_key`, `BackwardKey`, `crossTable`, `cross_table` in `src/Query/` returns zero results.

**1-line fix:** Add a `FkNavigator` service (or methods on `QueryManager`) that accepts a `ForeignKeyMeta` and a row's FK value and produces a `SelectQuery` scoped to the referenced row; add a `CrossTableSearchService` that fan-outs per-table LIKE queries using the metadata layer.

---

## 4. [MEDIUM] BLOB Streaming

**Promise:** `04-feature-inventory.md` §14 promises "BLOB download" under `Capability::BlobStreaming`, described as "Exposed as PHP `resource` stream, never as an in-memory string, per `01-vision.md` streaming goal." §21 of that document repeats the promise.

**Reality:** No BLOB streaming code exists in `src/Query/` or `src/Execution/`. `grep` of `BLOB`, `blob`, `stream` (as a concept distinct from result streaming), `resource`, `fpassthru` in `src/Query/` returns zero results. The `Capability` enum presumably includes `BlobStreaming` but there is no service that implements the streaming download of a BLOB column as a PHP resource.

**1-line fix:** Implement a `BlobStreamService` (or a method on `QueryManager`) that executes a single-column SELECT with an unbuffered cursor and yields the BLOB data as a PHP stream resource, gated on `Capability::BlobStreaming`.

---

## 5. [LOW] Streaming / Constant-Memory

**Promise:** `12-query-engine.md` §3 and `21-performance.md` §3 require generator-based streaming as the default, `$buffered = false` as the default polarity, and `StreamingResult` to throw on `seek()`/`count()`.

**Reality — all promises kept:**
- `QueryExecutorInterface::query()` defaults to `$buffered = false`.
- `QueryExecutor::query()` passes `streaming: !$buffered` to the connection.
- `StreamingResult` (`src/Connection/Result/StreamingResult.php`) throws `StreamingResultException` on `seek()` and `count()`, as required.
- `BufferedResult` exists as the opt-in alternative.
- `Paginator` correctly forces `buffered: true` for page-data and count queries — correct because pagination materialises a bounded page, not an unbounded stream.
- `BatchExecutor` yields `BatchStatementResult` objects — generator-based as designed.
- Integration test `QueryEngineAcceptanceIntegrationTest.php` covers streaming scenarios.

**Minor gap:** `21-performance.md` §8 promises a `ResultSetTooLargeException` thrown by eager-fetch convenience methods when a cap is exceeded. No such exception or cap-enforcement was found in `QueryManager` or `Paginator` beyond `Paginator::$maximumLimit` (which throws `InvalidArgumentException`, not the typed `ResultSetTooLargeException`). Severity: INFO.

---

## 6. [MEDIUM] SQL Injection Safety — SelectQueryRenderer

**Promise:** `12-query-engine.md` §7 states "The operator field of `WhereCondition` must be validated against the platform's operator list before constructing a `WhereCondition`." §1 states "prepared statements with bound parameters everywhere; never raw string interpolation of user input."

**Reality — parametric binding is correct:**
- All WHERE values are bound as `?` parameters — never interpolated into SQL strings.
- Column identifiers are quoted via `platform->quoteIdentifier()`.
- Aggregate function names are validated against `platform->getSupportedAggregateFunctions()`.
- There is a second operator check at render time in `SelectQueryRenderer::render()` (line 28) against `$this->platform->getOperators()`.

**Reality — operator allowlisting has a design deviation:**
`WhereCondition` validates the operator at construction time against a **hardcoded static list** (lines 18-24 of `WhereCondition.php`):

```
'=', '!=', '<>', '<', '<=', '>', '>=',
'LIKE', 'NOT LIKE', 'IN', 'NOT IN',
'IS NULL', 'IS NOT NULL', 'BETWEEN', 'NOT BETWEEN',
'REGEXP', 'NOT REGEXP',
```

This deviates from the design intent: `WhereCondition` construction is platform-agnostic and will accept `REGEXP`/`NOT REGEXP` even on platforms (MSSQL, Oracle) where these operators are unsupported — the error is deferred to render time (the second platform check in `SelectQueryRenderer`). More importantly, if a platform adds a new engine-specific operator (e.g., PostgreSQL's `~`, `~*`, `ILIKE`), it will be blocked at `WhereCondition` construction before reaching the platform check, making it impossible to use platform-specific operators without modifying the core VO.

**Injection risk: NONE** — the render-time platform check (`SelectQueryRenderer` line 28) acts as a hard gate; nothing user-supplied reaches the SQL string unbound. But the static list is architecturally incorrect and breaks extensibility.

**1-line fix:** Remove the static list from `WhereCondition`; move operator validation to `SelectQueryRenderer` only (or inject a `PlatformInterface` into `WhereCondition`'s constructor for platform-aware validation at construction time, as the design originally intended).

---

## 7. [LOW] Dead / Orphan Seams

### 7a. `src/Contracts/Query/` is nearly empty

`src/Contracts/Query/` contains only one file: `TableStatusProviderInterface.php`. The design requires all boundary-crossing interfaces to live in `Contracts/` (07 §1), yet `PaginatorInterface` lives in `src/Query/PaginatorInterface.php` — co-located with the concrete implementation. This violates the module boundary rule and makes `PaginatorInterface` harder to mock from outside the Query module without a full autoload of the Query namespace.

### 7b. `StatementSplitter` is in the wrong namespace

`StatementSplitter` lives in `src/Query/StatementSplitter.php` (namespace `SQLCraft\Query`) but its interface `StatementSplitterInterface` is in `src/Contracts/Execution/`. `12-query-engine.md` §4 places `StatementBatch` and the splitter firmly in `SQLCraft\Execution`. Consumers importing `StatementSplitterInterface` will find the concrete class in an unexpected namespace.

### 7c. `TransactionManager` is in the wrong namespace

`TransactionManager` lives in `src/Connection/TransactionManager.php` but `TransactionManagerInterface` is in `src/Contracts/Execution/`. The plan (`12-query-engine.md` §5, `07-module-breakdown.md` §9) places transaction management in the Execution module. This is a cross-module misplacement — the concrete sits in the Connection module (lower layer) while its contract is declared in the Execution module (higher layer), which is a dependency inversion violation relative to the module dependency table in `07-module-breakdown.md` §11.

### 7d. `Contracts/Execution/QueryHistoryEntry` is a concrete DTO, not an interface

`src/Contracts/Execution/QueryHistoryEntry.php` is a `final readonly class`, not an interface. The Contracts module is supposed to contain "nothing concrete; no implementations" (07 §1). This is a minor misfile.

---

## Summary Table

| # | Severity | Promise | Reality | 1-line fix |
|---|---|---|---|---|
| 2 | **HIGH** | `InsertQuery`, `UpdateQuery`, `DeleteQuery` builders in Query module (04 §14, 07 §9) | Classes entirely absent; only raw SQL execution available for DML | Implement the three builder VOs + renderers following the SelectQuery pattern |
| 3 | **MEDIUM** | FK navigation + backward keys + cross-table search as baseline Query features (04 §14) | `BackwardKeyMeta` DTO exists but no Query-layer service uses it; no FK navigator, no cross-table search | Add `FkNavigator` service and `CrossTableSearchService` to Query module |
| 4 | **MEDIUM** | BLOB streaming as PHP resource via `Capability::BlobStreaming` (04 §14) | No BLOB code exists in Query or Execution; feature entirely absent | Implement `BlobStreamService` with unbuffered cursor → PHP resource streaming |
| 6 | **MEDIUM** | Operator validated against `PlatformInterface::getOperators()` at `WhereCondition` construction (12 §7) | Static hardcoded allowlist in `WhereCondition` blocks platform-specific operators; render-time check still protects against injection | Remove static list from `WhereCondition`; rely solely on the render-time platform check |
| 7a | **LOW** | All boundary-crossing interfaces in `Contracts/` (07 §1) | `PaginatorInterface` lives in `src/Query/`, not `src/Contracts/Query/` | Move `PaginatorInterface` to `src/Contracts/Query/` |
| 7b | **LOW** | `StatementSplitter` in `SQLCraft\Execution` namespace (12 §4) | Class lives in `src/Query/` with namespace `SQLCraft\Query` | Move to `src/Execution/` |
| 7c | **LOW** | `TransactionManager` in Execution module (12 §5, 07 §9) | Lives in `src/Connection/TransactionManager.php` — wrong layer | Move concrete to `src/Execution/` or annotate as an intentional Connection-layer placement |
| 7d | **LOW** | `Contracts/` holds only interfaces (07 §1) | `QueryHistoryEntry` is a concrete `final readonly class` in `src/Contracts/Execution/` | Move `QueryHistoryEntry` to `src/DTO/` |
| 5 | **INFO** | `ResultSetTooLargeException` thrown by capped eager-fetch methods (21 §8) | `Paginator` uses `InvalidArgumentException` instead of the typed exception; no row-cap enforcement on `QueryManager::query()` | Add `ResultSetTooLargeException` and a configurable cap on eager fetch paths |
| 5 | **INFO** | Streaming/constant-memory design | Fully implemented: `$buffered=false` default, `StreamingResult` throws on seek/count, generators in `BatchExecutor`, integration tests present | No action needed |
