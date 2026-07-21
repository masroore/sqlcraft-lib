# SQLCraft Planning — 00: Overview

> Status: **Implemented release-candidate reference.** Gap-analysis phases 1–7 are complete; phase 8 documentation/config cleanup and phase 9 release verification remain.
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
  │                    SQLCraftFactory / DatabaseSession          │
  └──┬──────┬──────┬──────┬───────┬───────────┬─────────────────┘
     │      │      │      │       │           │
  ┌──▼──┐ ┌─▼──┐ ┌─▼──┐ ┌▼────┐ ┌▼────────┐ ┌▼──────────────┐
  │Query│ │Meta│ │ DDL│ │Exec │ │Import/  │ │Security/Users │
  │Svc  │ │data│ │Svc │ │Svc  │ │Export   │ │Privileges     │
  └──┬──┘ └─┬──┘ └─┬──┘ └─┬───┘ └─┬───────┘ └──────┬────────┘
     │      │      │      │       │                  │
  ┌──▼──────▼──────▼──────▼───────▼──────────────────▼────────┐
  │                Platform / Driver Layer                      │
  │   PlatformInterface → MySQL / MariaDB / PostgreSQL /       │
  │   SQLite / SQL Server platforms (implements DriverInterface)│
  └────────────────────────┬────────────────────────────────────┘
                           │ wraps
  ┌────────────────────────▼────────────────────────────────────┐
  │                 Connection Layer                             │
  │    ConnectionInterface → PdoConnection → \PDO               │
  │    ConnectionManager / TransactionManager                  │
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

## Reading Guide

| Topic | Authoritative document |
|---|---|
| Vision and principles | `01-vision.md`, `02-guiding-principles.md` |
| Domain model | `05-domain-model.md` |
| Architecture and modules | `06-package-architecture.md`, `07-module-breakdown.md` |
| Drivers and capabilities | `08-driver-architecture.md`, `09-capability-model.md` |
| Connections | `10-connection-layer.md` |
| Schema and DDL | `11-schema-services.md`, `13-ddl-services.md` |
| Query engine | `12-query-engine.md` |
| Import/export | `14-import-export.md` |
| Security and events | `15-security.md`, `16-events.md` |
| Public API and packaging | `18-public-api.md`, `19-package-structure.md` |
| Testing and performance | `20-testing.md`, `21-performance.md` |
| Roadmap and decisions | `23-roadmap.md`, `24-open-questions.md`, `25-final-review.md` |
| Gap closure | `plans/gap-analysis/` |
