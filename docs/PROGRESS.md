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
- [ ] T5: sequence, database, schema, and database-selection builders — not started
- [ ] T4: SQLite table recreation strategy — not started
- [ ] T5: DdlManager and execution wiring — not started
- [ ] M5 gate: DDL acceptance review — not started
