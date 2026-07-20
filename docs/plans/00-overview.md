# SQLCraft Planning — 00: Overview

> Status: **Planning phase. No code exists yet.**
> Last updated: 2026-07-20

---

## What SQLCraft Is

SQLCraft is a framework-independent, PDO-based PHP 8.4 library that exposes every database-administration capability (introspection, schema mutation, data CRUD, import, export, user/privilege management, SQL execution) as a clean, typed, composable SDK. It is distributed as a Composer package (`vendor/sqlcraft`) and installs unmodified into any PHP environment — Laravel, Symfony, Slim, Laminas, CLI scripts, REST/GraphQL APIs, IDE extensions, and AI agents. Applications call SQLCraft; SQLCraft never owns HTTP, sessions, templates, output, or UI.

## What SQLCraft Is Not

SQLCraft is not an ORM, not an Active Record layer, not a migration framework, not a query builder for application data models, and not a web application or UI of any kind. It contains no HTML, CSS, JavaScript, routing, controllers, session management, or rendering logic. It does not replace Adminer or phpMyAdmin — it is the reusable, embeddable SDK layer that tools like those are typically built on top of.

---

## The Problem SQLCraft Solves

Every framework application that needs a DB-admin panel today must either embed a monolithic tool (Adminer, phpMyAdmin), write custom introspection code, or reach for an ORM's introspection APIs that expose only a fraction of the needed surface. None of those options produce a reusable, typed, testable library. Capabilities are locked inside render loops, global state, and HTML-generation functions. SQLCraft extracts the pure, engine-aware logic — connection pooling, schema introspection, DDL generation, privilege management, streaming import/export — and packages it as a first-class PHP library with typed interfaces, a capability model, and a clean event bus, so any application layer can build on it.

---

## Intended Consumers

| Consumer | What They Need |
|---|---|
| Framework app developer | Embed a DB admin panel; needs all operations typed and secure |
| CLI tool author | Inspect/mutate schemas from shell; needs streaming export, no HTTP dependency |
| AI agent / LLM tool | Enumerate tables, read column metadata, execute parameterized SQL safely |
| IDE / editor plugin | Schema introspection, autocomplete data, FK graphs |
| REST / GraphQL API | Expose DB admin operations over HTTP without reimplementing them |
| Data pipeline | Chunked import, streaming export, connection multiplexing |

---

## ASCII Architecture Diagram

```
  ┌──────────────────────────────────────────────────────────────┐
  │                    Consumer Layer                            │
  │  (Framework controllers, CLI commands, AI tools, REST APIs)  │
  └────────────────────────┬─────────────────────────────────────┘
                           │ calls
  ┌────────────────────────▼─────────────────────────────────────┐
  │                    SQLCraft Facade                           │
  │         SQLCraft\Facade  /  ServiceContainer (opt.)          │
  └──┬──────┬──────┬──────┬───────┬───────────┬─────────────────┘
     │      │      │      │       │           │
  ┌──▼──┐ ┌─▼──┐ ┌─▼──┐ ┌▼────┐ ┌▼────────┐ ┌▼──────────────┐
  │Query│ │Meta│ │ DDL│ │Exec │ │Import/  │ │Security/Users │
  │Svc  │ │data│ │Svc │ │Svc  │ │Export   │ │Privileges     │
  └──┬──┘ └─┬──┘ └─┬──┘ └─┬───┘ └─┬───────┘ └──────┬────────┘
     │      │      │      │       │                  │
  ┌──▼──────▼──────▼──────▼───────▼──────────────────▼────────┐
  │                Platform / Driver Layer                      │
  │   PlatformInterface → MySQLPlatform / PgSQLPlatform /      │
  │   SQLitePlatform / MSSQLPlatform / OraclePlatform /        │
  │   MariaDBPlatform   (implements DriverInterface)            │
  └────────────────────────┬────────────────────────────────────┘
                           │ wraps
  ┌────────────────────────▼────────────────────────────────────┐
  │                 Connection Layer                             │
  │    ConnectionInterface → PdoConnection → \PDO               │
  │    ConnectionPool / LazyConnection / ReadReplicaConnection   │
  └─────────────────────────────────────────────────────────────┘

  Cross-cutting concerns (span every layer):
  ┌──────────────┐  ┌──────────────┐  ┌──────────────────────────┐
  │ Capability   │  │ Events       │  │ Exceptions               │
  │ Enum + Map   │  │ (PSR-14 bus) │  │ (typed hierarchy)        │
  └──────────────┘  └──────────────┘  └──────────────────────────┘
```

---

## Relationship to Adminer

Adminer (at `adminer/`) is used exclusively as a **behavioral specification** — a verified, production-tested reference for what operations a DB admin tool must support across MySQL, MariaDB, PostgreSQL, SQLite, MSSQL, and Oracle. SQLCraft does not copy Adminer's code, does not extend its classes, and does not replicate its architecture. Where Adminer has proven what a feature must do across engines, SQLCraft uses that knowledge to drive interface design. Where Adminer has accumulated technical debt (global state, HTML interleaved with logic, loose arrays), SQLCraft designs explicitly against those patterns. See `03-adminer-analysis.md` for the full reverse-engineering writeup.

---

## Reading Guide — All 26 Planning Documents

| # | File | One-Line Summary |
|---|---|---|
| 00 | `00-overview.md` | Executive summary, ASCII architecture, reading guide (this file) |
| 01 | `01-vision.md` | Long-term vision, design goals, personas, success metrics, v1.0 definition |
| 02 | `02-guiding-principles.md` | Engineering principles: SOLID, immutability, DI, capability-driven, PHP 8.4 idioms |
| 03 | `03-adminer-analysis.md` | Deep Adminer reverse-engineering: 4-class model, request lifecycle, debts, lessons |
| 04 | `04-feature-inventory.md` | Exhaustive feature-to-module mapping with per-engine coverage matrix |
| 05 | `05-namespace-structure.md` | Full PSR-4 namespace tree, bounded contexts, file layout conventions |
| 06 | `06-connection-layer.md` | ConnectionInterface, PdoConnection, pool, lazy/read-replica, DSN builders |
| 07 | `07-driver-platform.md` | DriverInterface, PlatformInterface, per-engine implementations, flavor/sub-vendor gating |
| 08 | `08-capability-model.md` | Capability enum, per-platform capability maps, CapabilityAware trait, guard helpers |
| 09 | `09-metadata-schema.md` | All introspection VOs (TableStatus, Field, Index, ForeignKey, Trigger, Routine …) |
| 10 | `10-ddl-service.md` | DDL generation API: CREATE/ALTER/DROP for tables, columns, indexes, constraints |
| 11 | `11-query-service.md` | Type-safe SELECT/INSERT/UPDATE/DELETE builders, pagination, FK navigation |
| 12 | `12-execution-service.md` | Raw SQL execution, multi-statement, explain, warnings, query history |
| 13 | `13-import-service.md` | Chunked SQL/CSV/TSV import, progress callbacks, transaction strategies |
| 14 | `14-export-service.md` | Streaming dump: SQL/CSV/TSV, gzip/bzip2/tar, scope (db/table/all), options |
| 15 | `15-security-service.md` | User/role management, GRANT/REVOKE matrix, privilege VOs, password hashing |
| 16 | `16-events.md` | PSR-14 event bus integration, event catalog, before/after hooks |
| 17 | `17-exceptions.md` | Exception hierarchy: ConnectionException, QueryException, CapabilityException … |
| 18 | `18-value-objects.md` | Immutable VOs: Identifier, QualifiedName, Collation, Charset, Limit, Page … |
| 19 | `19-collections.md` | Typed collection classes (TableCollection, FieldCollection, IndexCollection …) |
| 20 | `20-dto-patterns.md` | DTO design for insert/update/schema-change commands; readonly class conventions |
| 21 | `21-testing-strategy.md` | Unit, integration, contract tests; driver test matrix; fake/stub connection |
| 22 | `22-extension-points.md` | How to add a new driver, new capability, new export format; plugin contract |
| 23 | `23-streaming-memory.md` | Memory-efficient patterns: generators, chunked reads, resource handles for BLOBs |
| 24 | `24-security-model.md` | Input validation, SQL injection prevention, identifier quoting, privilege escalation guard |
| 25 | `25-versioning-stability.md` | SemVer policy, stability annotations, deprecation, BC break protocol |
