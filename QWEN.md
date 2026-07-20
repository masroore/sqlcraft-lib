# SQLCraft — Project Context

## Overview

SQLCraft is a **framework-independent, PDO-based database administration SDK** for PHP 8.4+. It exposes typed, capability-aware services for connection, introspection, DDL, query execution, import, and export — without owning HTTP, UI, or application state.

**What it is:** A reusable Composer library (`vendor/sqlcraft`) that any PHP application can embed — Laravel, Symfony, CLI tools, REST APIs, AI agents, IDE plugins.

**What it is not:** Not an ORM, not Active Record, not a migration framework, not a web UI. No HTML, CSS, JavaScript, routing, or rendering.

**Status:** Early development. Public API is not stable. Currently in Milestone M2 (Connection Layer).

---

## Architecture

```
Consumer Layer (controllers, CLI, AI tools)
        │
        ▼
SQLCraft Facade / ServiceContainer
        │
   ┌────┼────┬────┬─────┬──────────┐
   ▼    ▼    ▼    ▼     ▼          ▼
Query  Meta  DDL  Exec  Import/  Security
Svc    data  Svc  Svc   Export   /Users
        │
        ▼
Platform / Driver Layer
(MySQL, PgSQL, SQLite, MSSQL, Oracle, MariaDB)
        │
        ▼
Connection Layer
(PdoConnection → \PDO, Pool, Lazy, ReadReplica)
```

**Cross-cutting:** Capability model, PSR-14 events, typed exception hierarchy.

### Source Structure (`src/`)

| Directory | Purpose |
|-----------|---------|
| `Capabilities/` | Capability enum and per-platform maps |
| `Collections/` | Typed immutable collections |
| `Connection/` | ConnectionInterface, PdoConnection, pooling |
| `Contracts/` | All interfaces (ports) |
| `DDL/` | DDL generation (CREATE/ALTER/DROP) |
| `Driver/` | Driver implementations |
| `DTO/` | Readonly data transfer objects |
| `Events/` | PSR-14 domain events |
| `Exceptions/` | Typed exception hierarchy |
| `Execution/` | Raw SQL execution, multi-statement |
| `Export/` | Streaming dump (SQL/CSV/TSV, compression) |
| `Import/` | Chunked import with progress callbacks |
| `Metadata/` | Schema introspection services |
| `Platform/` | Platform-specific SQL generation |
| `Query/` | Type-safe query builders |
| `Schema/` | Schema mutation services |
| `Security/` | User/privilege management |
| `Support/` | Internal utilities |
| `ValueObjects/` | Immutable VOs (Identifier, QualifiedName, etc.) |

**Dependency rules** are enforced by Deptrac (`deptrac.yaml`). Layers may only depend on allowed layers — violations fail CI.

---

## Building & Running

### Prerequisites

- PHP 8.4+ with `ext-pdo`
- Composer 2.x
- Docker (for containerized workflow and integration tests)

### Quick Start

```bash
# Install dependencies
composer install

# Run full CI suite (static analysis + unit tests)
composer run ci
```

### Composer Scripts

| Command | Description |
|---------|-------------|
| `composer run test` | Unit tests only |
| `composer run test:integration` | Integration tests (requires databases) |
| `composer run test:contract` | Contract tests |
| `composer run test:all` | All test suites |
| `composer run stan` | PHPStan static analysis |
| `composer run psalm` | Psalm static analysis |
| `composer run cs` | PHP-CS-Fixer check (dry-run) |
| `composer run cs:fix` | PHP-CS-Fixer auto-fix |
| `composer run deptrac` | Deptrac dependency rules |
| `composer run rector` | Rector dry-run |
| `composer run rector:fix` | Rector apply fixes |
| `composer run ci` | Full CI: stan + psalm + cs + deptrac + rector + test |

### Makefile (Docker-based)

| Command | Description |
|---------|-------------|
| `make env` | Copy `.env.example` → `.env` |
| `make build` | Build PHP 8.4 Docker image |
| `make install` | Composer install in container |
| `make up` | Start database engines (MySQL, MariaDB, Postgres, MSSQL) |
| `make up-oracle` | Start engines + Oracle XE |
| `make down` | Stop services (data persists) |
| `make test` | Full CI suite in container |
| `make test-unit` | Unit tests in container |
| `make test-int` | Integration tests (requires `make up` first) |
| `make cs-fix` | Auto-fix code style |
| `make clean` | Remove caches and coverage reports |

---

## Testing

### Test Suites

| Suite | Location | Purpose |
|-------|----------|---------|
| `unit` | `tests/Unit/` | Fast, isolated tests with fakes/stubs |
| `integration` | `tests/Integration/` | Real database tests via Testcontainers |
| `contract` | `tests/Contract/` | Interface compliance tests |

Supporting directories:
- `tests/Fixtures/` — Test data and helpers
- `tests/Golden/` — Golden file comparisons

### Running Tests

```bash
# Unit tests (no database required)
composer run test

# Integration tests (requires Docker databases)
make up                    # Start databases first
composer run test:integration

# All tests
composer run test:all
```

### Mutation Testing

```bash
composer run infection
```

Requires minimum MSI of 80% and covered MSI of 90%.

---

## Development Conventions

### Code Style

- **PSR-12** base style with strict additions
- `declare(strict_types=1)` in every PHP file
- Strict comparisons (`===`, `!==`) enforced
- Strict parameter checking enabled

### PHP Patterns

- **Final classes** by default
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

- **PHPStan** at maximum level with strict rules
- **Psalm** at maximum level
- Both must pass with zero errors

### Architecture Rules

- Deptrac enforces layer dependencies (see `deptrac.yaml`)
- `Support` layer has no dependencies (leaf node)
- `Contracts` may depend on most layers (defines ports)
- Higher layers (Query, DDL, Import, Export) depend on lower layers

### Documentation

- Design documents in `docs/plans/` (00–25)
- Progress tracking in `docs/PROGRESS.md`
- Changelog in `CHANGELOG.md`

---

## Key Files

| File | Purpose |
|------|---------|
| `composer.json` | Package metadata, scripts, dependencies |
| `phpunit.xml.dist` | PHPUnit configuration |
| `phpstan.neon.dist` | PHPStan configuration |
| `psalm.xml` | Psalm configuration |
| `deptrac.yaml` | Architectural dependency rules |
| `.php-cs-fixer.dist.php` | Code style rules |
| `rector.php` | Automated refactoring rules |
| `infection.json.dist` | Mutation testing config |
| `docker-compose.yml` | Database containers for integration tests |
| `Makefile` | Common development tasks |

---

## Supported Databases

- MySQL
- MariaDB
- PostgreSQL
- SQLite
- Microsoft SQL Server (via `pdo_sqlsrv` or `pdo_dblib`)
- Oracle (via `pdo_oci`)

Each platform implements capability-aware behavior — not all features are available on all engines.
