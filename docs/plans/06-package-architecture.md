# 06 — Package Architecture

> **Status:** Design draft  
> **Scope:** Macro-architecture, layer model, dependency graph, bounded-context responsibilities, extensibility  
> **Architectural style:** Ports and Adapters (Hexagonal) layered over Domain-Driven bounded contexts

---

## 1. Architectural Style

SQLCraft uses a **ports-and-adapters (hexagonal) architecture** where:

- The **domain core** (Contracts + ValueObjects + DTO + Collections) defines interfaces (ports) and pure data structures that know nothing about concrete databases.
- **Adapters** (Driver, Platform, Connection) implement those interfaces for specific engines.
- **Application services** (Metadata, Schema, DDL, Query, Execution, Import, Export) orchestrate the ports to deliver use-cases. They depend only on interfaces, never on concrete adapters.
- **Cross-cutting concerns** (Security, Events, Capabilities, Exceptions, Support) are available to every layer but themselves depend only on Contracts.

This style was chosen over:

- *Layered architecture*: layers enforce a strict top-down chain but do not cleanly separate the domain from infrastructure. Hexagonal makes the "outside" (drivers, PDO) replaceable without touching application services.
- *Clean Architecture with Use Cases*: appropriate for user-facing applications; overkill for a library. SQLCraft has no "use cases" in the Application-User sense — consumers define their own use cases and call SQLCraft services.
- *Adminer's flat-include model*: every file can reference every other; no enforced boundary. This is why Adminer cannot be consumed as a library — it cannot be wired into a container.

---

## 2. Layers

```
┌────────────────────────────────────────────────────────────────────────┐
│                        CONSUMER APPLICATION                            │
│           (Laravel / Symfony / Slim / CLI / AI agent / IDE)            │
└───────────────────────────────┬────────────────────────────────────────┘
                                │  composer require vendor/sqlcraft
                                ▼
┌────────────────────────────────────────────────────────────────────────┐
│                    APPLICATION SERVICES LAYER                          │
│   Metadata · Schema · DDL · Query · Execution · Import · Export        │
│   (depend only on Contracts; framework-agnostic; no PDO exposure)      │
└────────┬──────────────────────────────────────┬────────────────────────┘
         │ uses                                  │ uses
         ▼                                       ▼
┌─────────────────────┐              ┌────────────────────────────────────┐
│  CROSS-CUTTING      │              │  DOMAIN CORE                       │
│  Security           │◄────────────►│  Contracts (interfaces/ports)      │
│  Events             │              │  ValueObjects · DTO · Collections  │
│  Capabilities       │              │  Exceptions · Support              │
│  Exceptions         │              └────────────────────────────────────┘
└─────────────────────┘                          ▲
                                                 │ implements
                                    ┌────────────┴───────────────────────┐
                                    │  ADAPTER LAYER                     │
                                    │  Driver · Platform · Connection     │
                                    │  (concrete engine implementations) │
                                    └────────────────────────────────────┘
                                                 ▲
                                                 │ wraps
                                    ┌────────────┴───────────────────────┐
                                    │  INFRASTRUCTURE                    │
                                    │  PDO (never exposed beyond adapter)│
                                    └────────────────────────────────────┘
```

---

## 3. Bounded Context Responsibilities

| Context | Namespace | Owns | Forbidden |
|---------|-----------|------|-----------|
| **Contracts** | `SQLCraft\Contracts\` | All cross-boundary interfaces (ports) | Any implementation, any concrete class |
| **ValueObjects** | `SQLCraft\ValueObjects\` | Immutable primitive domain types | DB calls, I/O, framework dependencies |
| **DTO** | `SQLCraft\DTO\` | Immutable read-model snapshots of DB metadata | Mutation, DB calls, business logic |
| **Collections** | `SQLCraft\Collections\` | Typed iterable wrappers for VOs/DTOs | DB calls, mutation of items |
| **Exceptions** | `SQLCraft\Exceptions\` | Exception hierarchy | Business logic, DB calls |
| **Support** | `SQLCraft\Support\` | Shared utilities (StringUtil, TypeUtil, etc.) | Domain logic, DB calls |
| **Connection** | `SQLCraft\Connection\` | PDO lifecycle, raw statement execution, quoting | Business logic, metadata interpretation |
| **Driver** | `SQLCraft\Driver\` | Connection factories, DSN construction, platform selection | Application-level logic |
| **Platform** | `SQLCraft\Platform\` | SQL dialect (quoting, pagination, type mapping, DDL fragments) | Full statement construction, metadata fetching |
| **Capabilities** | `SQLCraft\Capabilities\` | Capability enum, CapabilitySet, version-aware resolver | Platform implementation |
| **Metadata** | `SQLCraft\Metadata\` | Introspection services returning typed DTOs | DDL mutation, query construction |
| **Schema** | `SQLCraft\Schema\` | High-level schema comparison and diff | Import/Export, UI concerns |
| **DDL** | `SQLCraft\DDL\` | DDL statement generation from VOs | Execution (defers to Execution context) |
| **Query** | `SQLCraft\Query\` | Fluent SELECT/INSERT/UPDATE/DELETE builder | Execution, DDL |
| **Execution** | `SQLCraft\Execution\` | Statement execution, result hydration | Query building, DDL building |
| **Import** | `SQLCraft\Import\` | Streaming SQL/CSV/other format import | Export, UI |
| **Export** | `SQLCraft\Export\` | Database/table export to SQL/CSV/JSON/other | Import, UI |
| **Security** | `SQLCraft\Security\` | Privilege modelling, sanitisation helpers | HTTP, session, authentication |
| **Events** | `SQLCraft\Events\` | PSR-14 event objects and dispatcher port | Listener implementations (consumer-owned) |
| **Utilities** | `SQLCraft\Utilities\` | Pure helpers (pagination math, identifier sanitisation) | State, DB calls |

---

## 4. Dependency Rules (enforced by Deptrac or PHPStan layer rules)

```
Rule 1:  Contracts       → nothing (depends on no other SQLCraft namespace)
Rule 2:  ValueObjects    → Support only
Rule 3:  DTO             → ValueObjects, Support
Rule 4:  Collections     → ValueObjects, DTO, Support
Rule 5:  Exceptions      → Contracts, ValueObjects
Rule 6:  Support         → nothing
Rule 7:  Capabilities    → Contracts, ValueObjects, Exceptions
Rule 8:  Connection      → Contracts, ValueObjects, Exceptions, Support
Rule 9:  Driver          → Contracts, Connection, Platform, Capabilities, ValueObjects, Exceptions
Rule 10: Platform        → Contracts, ValueObjects, DTO, Collections, Capabilities, Exceptions, Support
Rule 11: Metadata        → Contracts, DTO, ValueObjects, Collections, Exceptions, Support
Rule 12: Schema          → Contracts, Metadata, DTO, ValueObjects, Collections, Exceptions
Rule 13: DDL             → Contracts, ValueObjects, Platform, Capabilities, Exceptions
Rule 14: Query           → Contracts, ValueObjects, Platform, Support, Exceptions
Rule 15: Execution       → Contracts, DTO, Collections, Exceptions, Events
Rule 16: Import          → Contracts, Execution, DTO, ValueObjects, Exceptions
Rule 17: Export          → Contracts, Execution, Metadata, DTO, Collections, Exceptions
Rule 18: Security        → Contracts, ValueObjects, Exceptions
Rule 19: Events          → Contracts, ValueObjects, DTO
Rule 20: Utilities       → Support only
```

**Critical rule:** No adapter (Driver, Platform, Connection) is imported by application services (Metadata, Schema, DDL, Query, Execution, Import, Export). Application services depend only on Contracts. This is the hexagonal boundary — adapters are wired at the composition root (DI container or factory) and injected as interface types.

---

## 5. Comparison with Adminer's Architecture

### Adminer's Flat-Include Model

```
adminer.php (5 000+ lines)
  includes drivers/mysql.inc.php
  includes drivers/pgsql.inc.php
  includes adminer/plugins/*.php
  global $driver, $connection, $adminer  ← singleton state
  free functions everywhere (table(), idf_escape(), q(), support()...)
  HTML echo interspersed with business logic
```

Problems: untestable in isolation; cannot be `composer require`d as a library; single active driver; no PSR standards; output coupling.

### Doctrine DBAL's Layering

Doctrine DBAL uses a similar layered approach (Connection → AbstractPlatform → schema tools) but differs:

- DBAL is query-builder-centric; schema introspection is secondary.
- DBAL leaks `Doctrine\DBAL\Connection` (wrapping PDO) into application code; SQLCraft never exposes a PDO-adjacent object past the Connection adapter.
- DBAL uses AbstractPlatform inheritance chains; SQLCraft uses segregated interfaces + composition, which reduces coupling when adding new platform features.
- DBAL has no capability enum; version gates are scattered in AbstractPlatform subclasses.
- SQLCraft's Capability enum + CapabilitySet provide structured, typed feature discovery — a first-class concern.

### SQLCraft Improvements

| Concern | Adminer | Doctrine DBAL | SQLCraft |
|---------|---------|---------------|----------|
| Library-consumable | No | Yes | Yes |
| Interface-first | No | Partial | Yes (Contracts are the center) |
| Capability system | String flags | None | Typed enum + set |
| Multiple connections | No | Yes | Yes |
| Immutable metadata | No (arrays) | Partial | Yes (readonly DTOs) |
| Driver extensibility | Globals | Subclass | Interface + registry |
| Static analysis | None | PHPStan 6 | PHPStan max + Psalm |

---

## 6. Extensibility

New bounded contexts slot in by:
1. Defining new interfaces in `Contracts` (e.g., `ReplicationInterface`).
2. Implementing in a new namespace (`SQLCraft\Replication\`).
3. Following the dependency rules in §4 — the new context depends on Contracts; nothing existing needs to change.

Third-party packages can publish their own driver by implementing `DriverInterface` + `PlatformInterface` and registering with `DriverRegistry::register()`. No SQLCraft core changes required.

A future `Backup` context would depend on `Contracts`, `Execution`, `Export`, and `Metadata` — all legal per the dependency rules. No circular dependency introduced.

