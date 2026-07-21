# SQLCraft Progress

## M0 — Project Setup
- [x] T1: local package toolchain — commit abf68ef — green — 2026-07-20
- [x] T2: package metadata and distribution files — commit 37309ab — green — 2026-07-20
- [x] T3: GitHub Actions workflows — commit 726c0b4 — green — 2026-07-20
- [x] Milestone gate: M0 — commit e40233e — green — 2026-07-20
- [x] Dependency maintenance: verify Testcontainers 1.0.10 is current stable — commits 4fcc1f5, bdb215c — green — 2026-07-20

## M1 — Foundation
- [x] T1: exceptions — commit 93561c7 — green — 2026-07-20
- [x] T2: Support utils — commit 6b889d9 — green — 2026-07-20
- [x] T3: core ValueObjects — commit 80a9d3d — green — 2026-07-20
- [x] T4: enum and default ValueObjects — commit f64129e — green — 2026-07-20
- [x] T5: named and connection ValueObjects — commit f9bb0da — green — 2026-07-20
- [x] T6: abstract immutable collection — commit 02f2453 — green — 2026-07-20
- [x] T7: core metadata DTOs — commit 57116a7 — green — 2026-07-20
- [x] T8: index and trigger DTOs — commit 9f15ed2 — green — 2026-07-20
- [x] T9: routine DTOs — commit e7da1de — green — 2026-07-20
- [x] T10: server and sequence DTOs — commit 3fb0436 — green — 2026-07-20
- [x] T11: database and schema DTOs — commit 6c43106 — green — 2026-07-20
- [x] T12: remaining DTOs — commit c43546a — green — 2026-07-20
- [x] T13: DTO collection wrappers — commit a7352c6 — green — 2026-07-20
- [x] T14: capability data types — commit 497705e — green — 2026-07-20
- [x] T15: event dispatcher contract — commit 0f1e37a — green — 2026-07-20
- [x] T16: contract dependency rules — commit 438471e — green — 2026-07-20
- [x] T17: connection and platform contract graph — commit a3f4e87 — green — 2026-07-20
- [x] T18: capability resolver contract — commit 2557c1f — green — 2026-07-20
- [x] T19: DDL builder contract — commit a302fe3 — green — 2026-07-20
- [x] T20: execution contracts — commit 8597e85 — green — 2026-07-20
- [x] T21: import and export contracts — commit 69bb702 — green — 2026-07-20
- [x] T22: export contract/support types — commit 1a254d7 — green — 2026-07-20
- [x] T23: import contract/support types — commit 78aea39 — green — 2026-07-20
- [x] T24: metadata collection wrappers — commit 4edde13 — green — 2026-07-20
- [x] T25: metadata inspector ports (typed subset) — commit 5f0c610 — green — 2026-07-20
- [x] T26: resolve DatabaseInspectorInterface type inventory — commit 6bc113f — green — 2026-07-20
- [x] T27: mutation coverage baseline — commit 96b35dc — green — 2026-07-20
- [x] M1 gate: Foundation acceptance review — commit d97b152 — green — 2026-07-20

## M2 — Connection Layer
- [x] T1: result implementations — commit 7fabd36 — green — 2026-07-20
- [x] T2: PDO exception translation — commit dbf30ab — green — 2026-07-20
- [x] T3: PDO connection factory seam — commit 06f741a — green — 2026-07-20
- [x] T4: PDO connection adapter — commit 124afec — green — 2026-07-20
- [x] T5: connection factory integration — commit 6a5ea91 — green — 2026-07-20
- [x] T6: transaction manager — commit 1df263d — green — 2026-07-20
- [x] T7: SQLite integration coverage — commit d30b3ab — green — 2026-07-20
- [x] T8: SQLite driver and platform stub — commit 7379e9a — green — 2026-07-20
- [x] T9: M2 acceptance gate — commit 156546f — green — 2026-07-20
## M3 — Platform & Driver Core
- [x] T1: abstract platform capability foundation — commit 9e88ca6 — green — 2026-07-20
- [x] T2: MySQL and MariaDB platform dialects — commit a7c1930 — green — 2026-07-20
- [x] T3: PostgreSQL platform dialect — commit c518e75 — green — 2026-07-20
- [x] T4: driver registry and built-in drivers — commit 21d3dd3 — green — 2026-07-20
- [x] T5: live platform conformance suite — commit d2ff6c8 — green — 2026-07-20
- [x] T6: M3 acceptance gate — commit d9566c8 — green — 2026-07-20
- [x] Milestone gate: M3 — commit 21170e5 — green — 2026-07-20

## M4 — Schema Introspection
- [x] T1: typed metadata factory contract — commit 95dd091 — green — 2026-07-20
- [x] T2: platform metadata factories — commit 6ef45f3 — green — 2026-07-20
- [x] T3: streaming table inspection contract — commit 10f261f — green — 2026-07-20
- [x] T4: core metadata inspectors — commit 6c1a7fb — green — 2026-07-20
- [x] T5: foreign-key metadata inspector — commit 8381c87 — green — 2026-07-20
- [x] T6: connection database-name seam — commit 7a907da — green — 2026-07-20
- [x] T7: table metadata inspector — commit 4d5da39 — green — 2026-07-20
- [x] T8: database/schema inspector — commit 908b684 — green — 2026-07-20
- [x] T9: server metadata inspector — commit 3cb5a95 — green — 2026-07-20
- [x] T10: view and sequence inspectors — commit 3241bdd — green — 2026-07-20
- [x] T11: routine, constraint, and user inspectors — commit 5110390 — green — 2026-07-20
- [x] T12: schema manager and metadata cache seam — commit 19cee9b — green — 2026-07-20
- [x] T13: batched column introspection — commit 5e999d2 — green — 2026-07-20
- [x] T14: introspection SQL golden snapshots — commit 06d5c0f — green — 2026-07-20
- [x] T15: SQLite schema integration coverage — commit 33fada4 — green — 2026-07-20
- [x] T16: MySQL MariaDB PostgreSQL schema integration coverage — commit 7fc688e — green — 2026-07-20
- [x] M4 gate: Schema Introspection acceptance review — commit 043ac43 — green — 2026-07-20

## M5 — DDL Services
- [x] T1: table and index builders — commit a035ffe — green — 2026-07-21
- [x] T2: alter table builder and dialect support — commit 4e33923 — green — 2026-07-21
- [x] T3: table and view lifecycle builders — commit b164516 — green — 2026-07-21
- [x] T4: trigger and routine builders — commit 56acebe — green — 2026-07-21
- [x] T5: sequence, database, schema, and database-selection builders — commit c8d2f01 — green — 2026-07-21
- [x] T6: SQLite table recreation strategy — commit f018706 — green — 2026-07-21
- [x] T7: DdlManager and execution wiring — commit f5bb18a — green — 2026-07-21
- [x] T8: SQLite recreation wiring and integration — commit 156537b — green — 2026-07-21
- [x] T9: SQLite ALTER acceptance coverage — commit c85a5ab — green — 2026-07-21
- [x] M5 gate: DDL acceptance review — commit e704a0b — green — 2026-07-21

## M6 — Query Engine
- [x] T1: Query executor — commit be5deb8 — green — 2026-07-21
- [x] T2: statement splitter and batch execution — commit e6beff2 — green — 2026-07-21
- [x] T3: SelectQuery builder and renderer — commit 4e3fdb9 — green — 2026-07-21
- [x] T4: paginator and Page DTO — commit dbdd4e7 — green — 2026-07-21
- [ ] T5: query history and QueryManager — not started
- [x] T5: query history and QueryManager — commit 6079776 — green — 2026-07-21
- [ ] T6: EXPLAIN and warnings services — not started
- [x] T6: EXPLAIN and warnings services — commit a7c816f — green — 2026-07-21
- [x] T7: M6 acceptance coverage — commit e0c5ff8 — green — 2026-07-21
- [ ] T8: M6 acceptance gate — not started
- [x] T8: M6 acceptance gate — commit pending — green — 2026-07-21
## M7 — Import/Export
- [ ] T1: sinks and format writer contracts — not started
- [x] T1: export sinks — commit f2439ec — green — 2026-07-21
- [ ] T2: format writer contracts and dump options hardening — not started
- [x] T2: format writer contracts and dump options hardening — commit 1774562 — green — 2026-07-21
- [ ] T3: SQL/CSV/TSV format writers — not started
- [x] T3: SQL/CSV/TSV format writers — commit 32e0ac5 — green — 2026-07-21
- [ ] T4: export orchestration — not started
- [x] T4: export orchestration — commit b873d10 — green — 2026-07-21
- [ ] T5: statement import pipeline — not started
- [x] T5: statement import pipeline — commit f25da34 — green — 2026-07-21
- [ ] T6: progress events and emission seam — not started
- [x] T6: progress events and emission seam — commit cfac6fd — green — 2026-07-21
- [ ] T7: round-trip and large-file coverage — not started
- [x] T7: round-trip and large-file coverage — commit 42e6a05 — green — 2026-07-21
- [ ] M7 gate: Import/Export acceptance review — not started
- [x] T8: CSV import pipeline — commit 6590608 — green — 2026-07-21
- [x] M7 gate: Import/Export acceptance review — commit bbdffda — green — 2026-07-21

## M8 — Remaining Platforms
- [x] T1: SQL Server platform — commit 36ee826 — green — 2026-07-21
- [x] T2: SQL Server driver — commit 0efc334 — green — 2026-07-21
- [x] T3: SQL Server connection and integration coverage — commits abd89b8, 7d3496e — green — 2026-07-21
- [x] T4: M8 MSSQL acceptance gate; Oracle deferred — commit e72b3ee — green — 2026-07-21

## M9 — Security & Events
- [x] T1: event dispatcher and catalog audit — commit 34d4383 — green — 2026-07-21
- [x] T2: security validation and redaction audit — commit 9e5578d — green — 2026-07-21
- [x] T2b: import/export event semantics — commit 4198c7b — green — 2026-07-21
- [x] T3: event taxonomy, query interception, and observability — commit 30056ea — green — 2026-07-21
- [x] T4: connection and transaction lifecycle events — commit dcdf417 — green — 2026-07-21
- [x] T5: DDL and metadata events — commit 7d29379 — green — 2026-07-21
- [x] T5b: capability event context — commit 8f62017 — green — 2026-07-21
- [x] T5c: platform capability event emission — commit d60a0df — green — 2026-07-21
- [x] T6: typed structural-SQL validation audit — commit 2df4ef4 — green — 2026-07-21
- [x] T7: credentials, redaction, and resource limits — commit 4e1685c — green — 2026-07-21
- [x] T8: M9 acceptance gate — commit 055d8df — green — 2026-07-21

## M10 — Documentation & v1.0
- [x] T1: examples and README — commit 9da4fc1 — green — 2026-07-21
- [ ] T2: API audit and changelog — not started
- [ ] T3: release verification and v1.0.0 tag — not started
