# Changelog

All notable changes to SQLCraft are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the public surface follows Semantic Versioning.

## [Unreleased]

No unreleased changes.

## [1.0.0] - 2026-07-21

First complete release-candidate implementation of the SQLCraft SDK.

### Added

- **M0 — Project Setup:** PHP 8.4 package metadata, PSR-4 autoloading, Composer
  toolchain, static analysis, architecture checks, Rector, PHPUnit, Docker
  development services, CI workflows, MIT licensing, and distribution hygiene.
- **M1 — Foundation:** strict typed contracts, immutable value objects, DTOs,
  collections, exceptions, capability data, validation utilities, and event
  dispatcher ports.
- **M2 — Connection Layer:** PDO connection adapter and factory seams, typed
  exception translation, streaming and buffered results, prepared statements,
  nested transactions with savepoints, database-name metadata, and SQLite
  integration coverage.
- **M3 — Platform & Driver Core:** platform contracts, capability resolution,
  MySQL, MariaDB, PostgreSQL, and SQLite dialects, driver registry, and shared
  platform conformance coverage.
- **M4 — Schema Introspection:** typed metadata inspectors, schema manager,
  batched column inspection, capability-gated operations, and golden SQL
  snapshots.
- **M5 — DDL Services:** immutable DDL builders, dialect rendering, DDL manager,
  common ALTER operations, and SQLite table recreation.
- **M6 — Query Engine:** parameterized query execution, immutable select query
  rendering, operator and aggregate allowlisting, streaming-default execution,
  buffered escape hatch, pagination, statement splitting, batch execution, and
  memory-bound coverage.
- **M7 — Import/Export:** streaming SQL, CSV, and TSV formats, import batching,
  CSV coercion policy, progress events, resource limits, and round-trip tests.
- **M8 — Remaining Platforms:** SQL Server platform, PDO SQLSRV driver, SQL Server
  integration and conformance coverage. Oracle is intentionally deferred.
- **M9 — Security & Events:** PSR-14-compatible event catalog, query interception,
  connection and transaction lifecycle events, schema/DDL and capability events,
  import/export telemetry, injection hardening, credential redaction, and DoS
  limits.
- Runnable SQLite examples for connection, introspection, DDL, query/pagination,
  import/export, Laravel wiring, Symfony wiring, and engine-independent code.

### Security

- PDO exceptions are translated at the connection boundary.
- Identifiers, operators, aggregate functions, data types, pagination, and batch
  sizes are validated before execution.
- Passwords are marked sensitive and redacted from DSNs, logs, and exception text.
- `CapabilityNotSupportedException` carries typed capability, platform, and
  version context.

### Deferred

- Oracle support, including `pdo_oci`, Oracle container/CI coverage, driver,
  platform, introspection, DDL, and conformance implementation.
- The planned convenience `SQLCraftFactory` / `DatabaseSession` aggregate and
  higher-level `SecurityGuardInterface` remain follow-up API work; the current
  release exposes the fully typed lower-level composition graph.

[Unreleased]: https://github.com/vendor/sqlcraft/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/vendor/sqlcraft/releases/tag/v1.0.0
