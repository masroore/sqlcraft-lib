# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

SQLCraft is a framework-independent PHP 8.4+ database administration SDK. It provides typed connection, metadata, DDL, query, import/export, capability detection, and security primitives while keeping PDO, HTTP, UI, and application state out of consumer code.

Supported platforms: SQLite, MySQL, MariaDB, PostgreSQL, Microsoft SQL Server. Oracle support is deferred to a future milestone.

## Build and Test Commands

**Environment setup:**
```bash
make build        # Build PHP 8.4 Docker image
make install      # Run composer install
```

**Run tests:**
```bash
make test              # Full CI suite (static analysis + unit tests)
make test-unit         # Unit tests only (no database services needed)
make test-int          # Integration tests (requires: make up)
composer run test:contract   # Contract tests
composer run test:golden     # Golden tests
```

**Start database services for integration tests:**
```bash
make up           # Start MySQL, MariaDB, PostgreSQL, MSSQL
make up-oracle    # Also start Oracle XE (deferred, not required for v1.0)
make down         # Stop services (data persists)
```

**Code quality:**
```bash
make cs           # PHP-CS-Fixer check (dry-run)
make cs-fix       # Auto-fix code style
make stan         # PHPStan analysis
make psalm        # Psalm analysis
make deptrac      # Dependency architecture rules
make rector       # Rector check (dry-run)
```

**Individual test suites:**
```bash
composer run test              # Unit tests
composer run test:integration  # Integration tests
composer run test:contract     # Contract tests
composer run test:golden       # Golden tests
composer run test:all          # All test suites
```

## Architecture

SQLCraft follows hexagonal architecture with strict dependency rules enforced by Deptrac:

1. **Contracts layer** (`src/Contracts/`) — All interfaces. No implementation dependencies.

2. **Value Objects & DTOs** (`src/ValueObjects/`, `src/DTO/`) — Immutable data structures. Depend only on Contracts.

3. **Core layers:**
   - `Driver/` — Engine-specific DSN construction and platform selection
   - `Platform/` — SQL dialect, quoting, capability checks, SQL rendering (per-engine)
   - `Connection/` — PDO boundary; PDO types never leak beyond this namespace
   - `Schema/` — Metadata introspection (tables, columns, indexes, constraints, routines, triggers)
   - `DDL/` — DDL builders (CREATE/ALTER/DROP for tables, indexes, views, etc.)
   - `Query/` — Type-safe query builders (SELECT/INSERT/UPDATE/DELETE with validation)
   - `Execution/` — Query execution with streaming/buffering control
   - `Import/Export/` — Streaming data import/export with format writers (SQL, CSV, JSON, XML, XLSX, HTML)
   - `Security/` — User management, privilege inspection, security guards
   - `Metadata/` — Schema caching (PSR-16 optional)
   - `Events/` — PSR-14 event dispatch (optional)

4. **Entry points:**
   - `SQLCraftFactory` — Service factory for creating `DatabaseSession` instances
   - `DatabaseSession` — Main API surface; provides access to schema, DDL, query, import, export, security managers

**Dependency flow:** Consumer → DatabaseSession → Manager services → Platform + Connection → Contracts. PDO types stay in `Connection/`. User-controlled values never interpolate into SQL; parameters are always bound.

## Key Patterns

**Streaming by default:** `QueryExecutor::query()` streams by default. Use `buffered: true` only when random access or `count()` is required. Import/export services chunk data and emit progress events.

**Capability detection:** Platforms declare capabilities via `PlatformInterface::has(Capability)`. Use `->require(Capability)` to throw typed exception if unsupported.

**Driver selection:** Pass `DatabaseDriver` enum to `ConnectionParameters`. `SQLCraftFactory` uses `DriverRegistry` to resolve driver by enum case.

**Event integration:** Optional PSR-14 event dispatcher observes operations (`QueryExecutedEvent`, `DdlExecutedEvent`, `SchemaChangedEvent`). Pass to `SQLCraftFactory` constructor.

**Metadata caching:** Optional PSR-16 cache for schema metadata. Cache invalidates on DDL/schema change events.

## Testing Strategy

- **Unit tests** (`tests/Unit/`) — No database required; test logic, validation, rendering
- **Integration tests** (`tests/Integration/`) — Require live database services; test real engine behavior
- **Contract tests** (`tests/Contract/`) — Cross-platform behavioral contracts
- **Golden tests** (`tests/Golden/`) — SQL rendering snapshots for DDL builders

Run unit tests without database services. Start `make up` before integration tests.

## Examples

All examples (`examples/01-*.php` through `examples/18-*.php`) are runnable with SQLite (no external database required). Each demonstrates a specific workflow: connection, schema introspection, DDL builders, query execution, pagination, transactions, import/export, events, credential providers, connection manager, capability detection, and format writers.

Run any example:
```bash
php examples/01-basic-connection.php
```

## Package Structure

- `src/` — All implementation code under `SQLCraft\` namespace
- `tests/` — Unit, integration, contract, golden test suites
- `examples/` — Runnable demonstrations (01–18)
- `vendor/` — Composer dependencies
- `docker/` — Dockerfiles for PHP 8.4 and database engines
- `docs/plans/` — Design documentation (roadmap, API policy, package structure, testing strategy, performance, final review)
