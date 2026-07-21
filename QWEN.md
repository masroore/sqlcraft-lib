# SQLCraft — Project Context

## Overview

SQLCraft is a **framework-independent, PDO-based database administration SDK** for PHP 8.4+. It exposes typed, capability-aware services for connection, introspection, DDL, query execution, import, and export — without owning HTTP, UI, or application state.

**What it is:** A reusable Composer library (`vendor/sqlcraft`) that any PHP application can embed — Laravel, Symfony, CLI tools, REST APIs, AI agents, IDE plugins.

**What it is not:** Not an ORM, not Active Record, not a migration framework, not a web UI. No HTML, CSS, JavaScript, routing, or rendering.

**Status:** v1.0 release-candidate phase. Milestones M0–M9 are green (see `docs/PROGRESS.md`). M10 (Documentation & v1.0) is in progress: the **v1.0.0 tag is blocked by the Infection mutation gate** (actual MSI 57% / covered MSI 75% vs required 80% / 90%).

**Supported platforms for v1:** SQLite, MySQL, MariaDB, PostgreSQL, Microsoft SQL Server. **Oracle is intentionally deferred** to a post-v1 milestone (placeholder dirs only at `src/Driver/Oracle/`, `src/Platform/Oracle/`).

---

## Architecture

Hexagonal (ports-and-adapters): application services depend only on `Contracts` interfaces; engine adapters are wired at the consumer's composition root and injected.

```
Consumer Layer (controllers, CLI, AI tools)
        │
        ▼
Consumer composition root (DI container or manual wiring — see examples/)
        │
   ┌────┼────┬────┬─────┬──────────┐
   ▼    ▼    ▼    ▼     ▼          ▼
Query  Meta  DDL  Exec  Import/  Security
Svc    data  Svc  Svc   Export   /Events
        │
        ▼
Platform / Driver Layer
(MySQL, MariaDB, PostgreSQL, SQLite, MSSQL)
        │
        ▼
Connection Layer
(PdoConnection → \PDO; PDO types never leave SQLCraft\Connection)
```

**Note:** the planned `SQLCraftFactory`/`DatabaseSession` composition-root facade (docs/plans/18) is **deferred** — consumers currently wire `DriverRegistry`, drivers, `PdoConnectionFactory`, and `SchemaManagerFactory` themselves. `examples/01-basic-connection/run.php` shows the canonical wiring.

### Source Structure (`src/`)

| Directory | Purpose |
|-----------|---------|
| `Capabilities/` | Capability enum, CapabilitySet, version-aware resolver |
| `Collections/` | Typed immutable collections (one per inspector return type) |
| `Connection/` | ConnectionInterface, PdoConnection, factory, transactions, results |
| `Contracts/` | All interfaces (ports), subdivided by module |
| `DDL/` | DDL builders (CREATE/ALTER/DROP) + `DdlManager` execution wiring |
| `Driver/` | DriverInterface implementations + `DriverRegistry` |
| `DTO/` | Readonly metadata read-models (ColumnMeta, TableStatus, …) |
| `Events/` | PSR-14 domain events + typed dispatcher helpers |
| `Exceptions/` | Typed exception hierarchy (rooted at `SQLCraftException`) |
| `Execution/` | QueryExecutor, StatementSplitter, BatchExecutor, QueryManager |
| `Export/` | Streaming dump (SQL/CSV/TSV writers, sinks, DumpOptions) |
| `Import/` | Chunked SQL + CSV import with progress events |
| `Metadata/` | Schema introspection services, inspectors, metadata factories |
| `Platform/` | Per-engine dialects (quoting, pagination, type mapping, SQL rendering) |
| `Query/` | SelectQuery builder/renderer, Paginator, WhereCondition |
| `Schema/` | SchemaManager aggregate, metadata cache seam |
| `Security/` | IdentifierQuoter, OperatorValidator (boundary validation) |
| `Support/` | Internal utilities (leaf node — no SQLCraft dependencies) |
| `ValueObjects/` | Immutable VOs (Identifier, QualifiedName, DataType, ConnectionParameters, …) |

**Dependency rules** are enforced by Deptrac (`deptrac.yaml`, `deptrac/deptrac` ^4.7). Layers may only depend on allowed layers — violations fail CI. `Support` has no dependencies (leaf); application services never import adapters (Driver/Platform/Connection).

---

## Building & Running

### Prerequisites

- PHP 8.4+ with `ext-pdo` (plus per-engine PDO extensions for live databases)
- Composer 2.x
- Docker (for the containerized workflow and integration tests)

### Quick Start

```bash
# Install dependencies
composer install

# Run a no-container example (SQLite in-memory)
php examples/01-basic-connection/run.php

# Run full CI suite (static analysis + unit + golden tests)
composer run ci
```

### Composer Scripts

| Command | Description |
|---------|-------------|
| `composer run test` | Unit tests only |
| `composer run test:integration` | Integration tests (requires database containers) |
| `composer run test:contract` | Contract tests (platform conformance) |
| `composer run test:golden` | Golden-file SQL snapshot tests |
| `composer run test:all` | All test suites |
| `composer run stan` | PHPStan static analysis (max level, strict rules) |
| `composer run psalm` | Psalm static analysis |
| `composer run cs` | PHP-CS-Fixer check (dry-run) |
| `composer run cs:fix` | PHP-CS-Fixer auto-fix |
| `composer run deptrac` | Deptrac dependency rules |
| `composer run rector` | Rector dry-run |
| `composer run rector:fix` | Rector apply fixes |
| `composer run infection` | Mutation testing (gate: MSI ≥ 80%, covered MSI ≥ 90%) |
| `composer run ci` | Full CI: stan + psalm + cs + deptrac + rector + test + test:golden |

### Makefile (Docker-based)

| Command | Description |
|---------|-------------|
| `make env` | Copy `.env.example` → `.env` |
| `make build` | Build PHP 8.4 Docker image |
| `make install` | Composer install in container |
| `make up` | Start database engines (MySQL, MariaDB, Postgres, MSSQL) |
| `make up-oracle` | Start engines + Oracle XE profile (not needed for v1 work) |
| `make down` | Stop services (data persists) |
| `make test` | Full CI suite in container |
| `make test-unit` | Unit tests in container |
| `make test-int` | Integration tests (requires `make up` first) |
| `make cs-fix` | Auto-fix code style |
| `make clean` | Remove caches and coverage reports |
| `make fresh` | Clean + rebuild containers (**destroys data volumes**) |

### Examples

All numbered examples under `examples/` are standalone runnable scripts using SQLite by default (no containers required): `01-basic-connection`, `02-schema-introspection`, `03-ddl-create-table`, `04-query-and-paginate`, `05-import-export`, `06-laravel-integration`, `07-symfony-integration`, `08-multi-engine-comparison`.

---

## Testing

### Test Suites (`phpunit.xml.dist`)

| Suite | Location | Purpose |
|-------|----------|---------|
| `unit` | `tests/Unit/` | Fast, isolated tests with fakes/stubs |
| `integration` | `tests/Integration/` | Real database tests (Testcontainers / docker-compose engines) |
| `contract` | `tests/Contract/` | `PlatformInterface` conformance suite |
| `golden` | `tests/Golden/` | Introspection SQL snapshot fixtures |

PHPUnit runs strict: `failOnRisky`, `failOnWarning`, `beStrictAboutTestsThatDoNotTestAnything`. Supporting dirs: `tests/Fixtures/` (shared helpers/data).

### Running Tests

```bash
composer run test                  # unit (no database required)
make up                            # start database containers first, then:
composer run test:integration      # integration
composer run test:all              # everything
```

### Mutation Testing

```bash
composer run infection             # gate: --min-msi=80 --min-covered-msi=90
```

Thresholds live in the composer script, not `infection.json.dist`. **Currently failing (57% / 75%) — this is the sole v1.0.0 release blocker** (see `docs/M10-RELEASE-BLOCKER.md`). `composer run ci` does not include infection.

---

## Development Conventions

### Code Style (`.php-cs-fixer.dist.php`)

- **PSR-12** base style with risky rules enabled
- `declare(strict_types=1)` in every PHP file
- Strict comparisons (`===`, `!==`) enforced (`strict_comparison`)
- Strict parameter checking (`strict_param`)

### PHP Patterns

- **Final classes** by default (platforms are subclassable only where flavor inheritance requires it, e.g. `MariaDbPlatform extends MySQLPlatform`)
- **Readonly classes** for DTOs and Value Objects
- **Constructor promotion** for concise definitions
- **Typed properties** and return types everywhere
- **Immutability** preferred — Value Objects are always immutable
- **Named arguments** supported in public APIs

Example Value Object:
```php
<?php

declare(strict_types=1);

namespace SQLCraft\ValueObjects;

final readonly class Identifier
{
    public function __construct(
        public string $name,
    ) {
    }
}
```

### Static Analysis

- **PHPStan** at `level: max` with `phpstan-strict-rules`, analysing `src` + `tests`
- **Psalm** at maximum level
- Both must pass with zero errors

### Architecture Rules

- Deptrac enforces layer dependencies (see `deptrac.yaml`); violations fail CI
- `Support` is a leaf (no dependencies)
- `Contracts` may depend on most layers (defines ports)
- Application services (Query, DDL, Import, Export, …) depend on Contracts — never on concrete adapters
- DDL execution should flow through `DdlManager`/`QueryExecutor::executeDdl()` so events, SQLite table recreation, and (future) cache invalidation fire — see audit caveat below

### Git Conventions

- Conventional Commits: `docs(release): …`, `fix(import): …`, `build(tooling): …`
- Milestone work is tracked task-by-task in `docs/PROGRESS.md` with commit refs

---

## Documentation Map

| Location | Purpose |
|----------|---------|
| `docs/plans/00–25` | **Authoritative design** — vision, domain model, architecture, per-module specs, roadmap, open questions |
| `docs/PROGRESS.md` | Milestone/task tracking (M0–M10) with commits and gate status |
| `docs/audit/v1/` | Plan-vs-implementation conformance audit (2026-07-21, baseline `master@6d50506`): `00-summary.md` + 8 area reports |
| `docs/audit/AUDIT-PROMPT.md` | Reusable prompt for rerunning the conformance audit (future audits → `docs/audit/v{N}/`) |
| `docs/M10-RELEASE-BLOCKER.md` | Infection threshold blocker record |
| `docs/M10-API-AUDIT.md`, `docs/M9-AUDIT.md`, `docs/M8-MSSQL-STATUS.md` | Milestone audit records |
| `docs/IMPLEMENTATION-PROMPT.md` | Implementation workflow prompt |
| `CHANGELOG.md` | Release changelog |

Key plan docs: `23-roadmap.md` (milestones/gates), `18-public-api.md` (API policy; §7 lists acknowledged deferrals), `19-package-structure.md` (layout/tooling), `20-testing.md` (test strategy), `21-performance.md` (streaming/query-count guarantees).

---

## Current State & Known Gaps (v1 audit, 2026-07-21)

The full findings live in `docs/audit/v1/00-summary.md`. Headline items a future session should know before "discovering" them:

- **Release blocker:** Infection MSI 57%/75% vs 80%/90% gate.
- **Oracle deferred** — 5 engines ship in v1; `integration.yml` Oracle job is stale.
- **No composition-root facade** (`SQLCraftFactory`/`DatabaseSession`) — acknowledged deferral (plan 18 §7); wire services manually per `examples/`.
- **Connection decorators unimplemented:** Pool/Lazy/ReadReplica, `ConnectionManager`, `CredentialProviderInterface` (credentials live on `ConnectionParameters`).
- **Export DDL is lossy:** `ExportSource::getTableDdl()` hand-rolls minimal `CREATE TABLE` instead of using `DdlBuilder`; several `DumpOptions` flags are dead (`includeTriggers/Routines/Events`, `dataStyle`, `databaseStyle`).
- **DDL builders' `execute()` bypasses `DdlManager`** — direct `AlterTableBuilder::execute()` on SQLite emits invalid SQL; route through `DdlManager`.
- **Only SELECT builders exist** — INSERT/UPDATE/DELETE builders promised in plans 00/05/06/07 were dropped from plan 12 and never built.
- **MSSQL introspection** exists in the platform but is not wired through `SchemaManagerFactory` (throws for `sqlserver`).
- **Inert options:** transaction isolation level never applied; SSL options never mapped to PDO; import `statementTimeoutMs` never read; per-query `wrapWithTimeout()` returns null on all platforms.

Recommended fix order (audit §7): scope-honesty doc amendments → correctness hazards → wire-or-remove dead options → missing subsystems → infection gate.

---

## Key Files

| File | Purpose |
|------|---------|
| `composer.json` | Package metadata, scripts, dependencies (runtime: `php ^8.4` + `ext-pdo` only) |
| `phpunit.xml.dist` | PHPUnit configuration (unit/integration/contract/golden suites) |
| `phpstan.neon.dist` | PHPStan configuration (max level + strict rules) |
| `psalm.xml` | Psalm configuration |
| `deptrac.yaml` | Architectural dependency rules |
| `.php-cs-fixer.dist.php` | Code style rules (PSR-12 + strict) |
| `rector.php` | Automated refactoring rules |
| `infection.json.dist` | Mutation testing config (thresholds in composer script) |
| `docker-compose.yml` | Database containers for integration tests |
| `Makefile` | Common development tasks |
| `.env.example` | Environment template for docker-compose |

---

## Supported Databases

- MySQL
- MariaDB (via `MariaDbPlatform extends MySQLPlatform`, flavor-aware)
- PostgreSQL
- SQLite
- Microsoft SQL Server (via `pdo_sqlsrv` or `pdo_dblib`)
- Oracle — **deferred post-v1** (via `pdo_oci`; design exists in plans 08/09/13, code is placeholder only)

Each platform implements capability-aware behavior via `buildCapabilityMatrix()` (always-on + version-gated capabilities) — not all features are available on all engines. Query capabilities with `CapabilitySet::has()` / `require()`.
