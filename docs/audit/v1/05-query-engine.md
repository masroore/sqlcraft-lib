# 05 — Query Engine & Execution Audit

> **Status:** Final
> **Audit date:** 2026-07-21
> **Baseline:** `master @ 6d50506`
> **Plans reviewed:** `12-query-engine.md` (plus the write-builder promises in `00-overview.md`, `05-domain-model.md` §7, `06-package-architecture.md` §3, `07-module-breakdown.md`)
> **Implementation reviewed:** `src/Query/`, `src/Execution/`, `src/Contracts/Execution/`, `src/Contracts/Query/`, `src/DTO/`

---

## 1. Gaps

- **MODERATE — INSERT/UPDATE/DELETE builders and FK navigation never built.** Plans 00 (line 98: "Type-safe SELECT/INSERT/UPDATE/DELETE builders, pagination, FK navigation"), 05 §7 (`QueryBuilder` → "Fluent SELECT/INSERT/UPDATE/DELETE construction"), 06 §3 (Query context owns "Fluent SELECT/INSERT/UPDATE/DELETE builder"), and 07 (lines 50, 367: "Fluent query builder returning SQL strings (SELECT/INSERT/UPDATE/DELETE) … returns `PreparedStatement { sql, bindings }`") all promise write-side builders. Plan 12 — the detailed design — covers only SELECT, execution, pagination, history, and EXPLAIN; the write builders were **silently dropped** between the architecture docs and the module doc. `src/Query/` contains only `SelectQuery` + renderer + pagination + conditions. Feature inventory §14 (Data: Browse/Select/Insert/Update/Delete/Clone/Search) is Adminer's core workflow; consumers must use raw SQL for it. (Cross-ref: [01](01-domain-model.md).)

- **MODERATE — Multi-resultset iteration not implemented (plan 12 §4.2).** The plan requires `BatchExecutor` to detect additional result sets via `PDOStatement::nextRowset()` and yield extra `ResultInterface`s (e.g., MySQL `CALL`). `src/Execution/BatchExecutor.php` yields exactly one `BatchStatementResult` per statement; `grep nextRowset` across `src/` returns nothing.

- **MODERATE — Per-query timeout has no engine support (plan 12 §10).** The plan specifies per-engine mechanisms (MySQL `MAX_EXECUTION_TIME`, MariaDB `max_statement_time`, PgSQL `statement_timeout`, etc.) via `wrapWithTimeout()`. Only `AbstractPlatform::wrapWithTimeout()` exists and returns `null` (`src/Platform/AbstractPlatform.php:456`); no platform overrides it. So `QueryExecutor::queryWithTimeout()` always returns `null` for any `timeoutMs > 0` — the plumbing exists but no engine actually applies a timeout.

- **MODERATE — Oracle EXPLAIN coverage absent (plan 12 §8).** The plan's §8 table includes Oracle (`EXPLAIN PLAN FOR` + `DBMS_XPLAN`). There is no Oracle platform in `src/Platform/`, so the Oracle row is entirely unimplemented at this layer. (Cross-ref: [02](02-driver-platform-capabilities.md).)

- **MINOR/MODERATE — `BatchExecutor` warning collection missing (plan 12 §9).** Plan: "`BatchExecutor` collects warnings per statement when `$collectWarnings = true`." `BatchExecutorInterface::executeBatch()` has only `$stopOnError`; no `$collectWarnings`, no `WarningsProvider` integration.

- **MINOR/MODERATE — `ExplainResult::$tree` / `$json` never populated (plan 12 §8).** The plan defines `tree` and `json` plan fields. `src/Execution/ExplainService.php` only sets `engine`, `rows`, `elapsedMs`; `tree`/`json` are always `null` even though `MySQLPlatform::getExplainSql()` emits `EXPLAIN FORMAT=JSON` (its JSON output lands in `rows`, not `json`).

- **MINOR/MODERATE — Transaction isolation-level capability validation missing (plan 12 §5.2).** Plan: "The `TransactionManager` validates the requested level against `CapabilitySet` before applying it." `TransactionManager::begin()` passes `$isolationLevel` straight to `beginTransaction()` with no capability check — and the level is never applied anyway (see [03](03-connection-layer.md)). Savepoint nesting itself is correctly implemented.

## 2. Drift

- **MINOR — Operator allowlisting location.** Plan 12 §7 requires the operator be validated against the *platform's* `getOperators()` at `WhereCondition` construction. Instead `src/Query/WhereCondition.php` validates against a hardcoded static list at construction; the platform-specific check is deferred to `SelectQueryRenderer::render()`. The hardcoded list happens to equal `AbstractPlatform::getOperators()`, so behavior is close but not platform-driven at VO construction as specified.

- **MINOR — Namespace placement.** Several types live elsewhere than the plan shows: `StatementBatch` in `Contracts\Execution` (plan §4.1: `SQLCraft\Execution`); concrete `StatementSplitter` in `src/Query/` (plan scope: `Execution`); `PaginatorInterface` in `src/Query/` (plan §6.4: `Contracts\Query`); concrete `TransactionManager` in `src/Connection/` (plan scope: `Execution`); `Page` DTO in `src/Query/Page.php` (plan groups it with DTOs).

- **MINOR (beneficial) — Renderer clause order corrected.** Plan 12 §7.1's sample appends `GROUP BY` before `WHERE` (invalid SQL); `SelectQueryRenderer::render()` correctly orders `WHERE → GROUP BY → ORDER BY`.

- **MINOR — MariaDB EXPLAIN form.** `MariaDbPlatform` inherits MySQL's `EXPLAIN ANALYZE`/`FORMAT=JSON`; plan 12 §8 notes MariaDB's `ANALYZE` differs (10.1+, `ANALYZE FORMAT=JSON`). No MariaDB-specific override.

## 3. Extras

- **`QueryManager` facade** (`src/Execution/QueryManager.php`) — delegates to executor + splitter + batch executor; not mentioned anywhere in plan 12.
- **Defensive limits** — `Paginator::$maximumLimit` (default 10000) and `BatchExecutor::$maximumStatements` (default 1000) guards; not in plan. (The batch cap matches plan 15 §11.1's security intent; note the *import* path does not apply it — see [07](07-security-events-plugins-api.md).)
- **Event dispatch + cancellation in `QueryExecutor`** — `BeforeQueryExecuted`/`AfterQueryExecuted`/`QueryFailedEvent`/`SlowQueryDetectedEvent`/`BeforeDdlExecuted`/`AfterDdlExecuted`, plus cancellation via `OperationCancelledException`. Plan 12 references events only generically (§2); the slow-query event matches plan §1/§10 observability goals; cancellation detail is beyond this plan.
- **Constructor validation** — `SelectQuery` (`limit >= 1`, `offset >= 0`) and `ColumnSelection` aggregate-name regex check; unspecified in plan.
- **`TableStatusProviderInterface`** (`src/Contracts/Query/`) — dedicated port for approximate row counts; plan 12 §6.3 instead references `TableStatus::$rows` from schema services. Reasonable adaptation, used by `Paginator` for the `totalApprox` path.

## 4. Faithful to Plan

- **`SelectQuery` VO + platform-aware renderer** per plan 12 §7 (columns, where, group/having, order, limit/offset; rendering delegated to the platform for pagination syntax).
- **`Paginator`/`Page` with the separate-COUNT strategy and approximate-count path** per plan 12 §6.
- **`StatementSplitter`** handles DELIMITER directives, quotes, and comments per plan 12 §4 (but lacks PgSQL dollar-quoting — see [06](06-import-export.md) where the import plan mandates it).
- **`QueryExecutor`** — streaming-default execution, DDL split routing, history recording — per plan 12 §2/§3.
- **The full `QueryHistory` trio** (`InMemoryQueryHistory`, `NullQueryHistory`, `CallbackQueryHistory`) matches plan 12 §11.
- **EXPLAIN and warnings services exist** per plan 12 §8/§9 (`ExplainService`, `QueryWarning` DTO, warnings provider), modulo the unpopulated fields noted above.

## 5. Summary

The core of plan 12 is faithfully built: `SelectQuery` + renderer, `Paginator`/`Page`, the `StatementSplitter`, streaming-default `QueryExecutor`, and the `QueryHistory` trio all match the design. The notable shortfalls are the unimplemented multi-resultset iteration (§4.2), the per-query timeout that has interface plumbing but zero engine implementations (§10), `ExplainResult` tree/json fields that are never filled, and missing Oracle EXPLAIN coverage. The most consequential gap charged to this area predates plan 12: the INSERT/UPDATE/DELETE builders and FK navigation promised by plans 00/05/06/07 were silently dropped and never built. Remaining differences are minor: operator validation moved off the VO, several namespace relocations, and defensive extras (`QueryManager`, statement/limit caps) not in the plan.
