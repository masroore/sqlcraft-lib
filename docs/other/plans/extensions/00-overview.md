# SQLCraft Extension System — Implementation Plan

> **Authoritative replacement:** `docs/other/plans/extensions-revised/04-implementation-handoff.md` and `03-verification.md`. This document is retained for history and is not an active implementation requirement.


> **Created:** 2026-07-22
> **Status:** SUPERSEDED — historical reference only — not yet implemented
> **Purpose:** Gap analysis and implementation roadmap for SQLCraft's extension/plugin system
> **Related:** `docs/other/plans/17-plugin-system.md` (design specification)

---

## 1. Executive Summary

The superseded draft described Adminer's hook inventory as approximate allowing plugins to customize authentication, data formatting, export/import behavior, UI rendering, and database introspection. SQLCraft aims to provide similar extensibility **for logic/data operations only** (no UI/rendering hooks), using a three-mechanism model:

1. **PSR-14 events** — for cross-cutting observation and interception
2. **Swappable service implementations via DI** — for wholesale behavior replacement
3. **Explicit extension interfaces** — for narrow, well-defined customization seams

### Current Status

**What EXISTS (implemented in SQLCraft):**

✅ PSR-14 event system with 30+ event classes
✅ `FormatRegistry` with `registerWriter()` / `registerReader()`
✅ `DriverRegistry` with `register()` / `registerAlias()`
✅ `CredentialProviderInterface`, `MetadataCacheInterface`, `QueryHistoryInterface`
✅ `FormatWriterInterface`, `FormatReaderInterface`, `SinkInterface`, `ImportSourceInterface`
✅ `BeforeQueryExecuted::replaceSql()` for query interception
✅ All `Contracts\Metadata\*InspectorInterface` as swappable services
✅ `DriverInterface`, `PlatformInterface` for third-party database engines

**What is MISSING (needs implementation):**

❌ `SQLCraft\Extension\` namespace — no extension helper classes
❌ `AbstractPlatformDecorator` — no typed helper for platform decoration pattern
❌ `CredentialProviderChain` — no composite credential provider
❌ `QueryHistory` / `MetadataCache` default implementations
❌ `ExtensionBundle` pattern — no grouping mechanism for multi-extension registration
❌ Built-in extension implementations — no SQLCraft equivalents of Adminer plugins
❌ `@api` / `@internal` stability annotations — not systematically applied
❌ Extension author guide documentation

---

## 2. Adminer Hook → SQLCraft Mechanism Mapping Summary

The superseded draft's hook grouping (replaced by the 79-hook matrix):

- **~20 hooks** map to SQLCraft logic mechanisms (events, swappable interfaces, extension interfaces)
- **~6 hooks** map to constructor parameters / value objects (not extension points)
- **~4 hooks** are out of scope (web forms, sessions, brute-force protection)
- **~30 hooks** are UI/rendering concerns (explicitly excluded from SQLCraft)

### High-Value Hooks Requiring Implementation

| Adminer Hook | SQLCraft Mechanism | Implementation Status |
|---|---|---|
| `afterConnect` | `ConnectionOpenedEvent` listener | ✅ Event exists, needs docs |
| `credentials` | `CredentialProviderInterface` | ✅ Interface exists, needs chain impl |
| `databases` | `ServerInspectorInterface::getDatabases()` | ✅ Swappable via DI |
| `operators` | `PlatformInterface::getOperators()` | ✅ Core platform method |
| `selectQuery` | `BeforeQueryExecuted::replaceSql()` | ✅ Implemented |
| `dumpFormat` | `FormatRegistry::registerWriter()` | ✅ Implemented |
| `dumpOutput` | `SinkInterface` implementations | ✅ Interface exists |
| `importServerPath` | `ImportSourceInterface` | ✅ Interface exists |
| `foreignKeys` | `ForeignKeyInspectorInterface` | ✅ Swappable via DI |
| `backwardKeys` | `ForeignKeyInspectorInterface::getReferencingKeys()` | ✅ Swappable via DI |
| Platform tweaks | `PlatformInterface` decoration | ❌ **Needs `AbstractPlatformDecorator`** |
| Multiple credentials | Chain pattern | ❌ **Needs `CredentialProviderChain`** |
| Query logging | `AfterQueryExecuted` listener | ✅ Event exists, needs built-in impl |
| Database filtering | Schema filter pattern | ❌ **Needs `SchemaFilterInterface`** |

---

## 3. Gap Analysis — What Needs Implementation

### 3.1 Core Extension Infrastructure

**Priority: HIGH (P0 — blocking other extensions)**

| Component | Status | Notes |
|---|---|---|
| `SQLCraft\Extension\AbstractPlatformDecorator` | ❌ Missing | Helper class for decorating `PlatformInterface` |
| `SQLCraft\Extension\CredentialProviderChain` | ❌ Missing | Composite pattern for credential providers |
| `SQLCraft\Extension\ExtensionBundle` | ❌ Missing | Grouping multiple registrations |
| `SQLCraft\Extension\ServiceProviderInterface` | ❌ Missing | Laravel/Symfony-style service provider pattern |

### 3.2 Default Implementations

**Priority: MEDIUM (P1 — usability for common cases)**

| Component | Status | Notes |
|---|---|---|
| `SQLCraft\Execution\InMemoryQueryHistory` | ❌ Missing | Default `QueryHistoryInterface` implementation |
| `SQLCraft\Execution\NullQueryHistory` | ❌ Missing | No-op implementation |
| `SQLCraft\Schema\InMemoryMetadataCache` | ❌ Missing | Simple PSR-16-compatible cache |
| `SQLCraft\Schema\NullMetadataCache` | ✅ **EXISTS** | Already implemented |
| `SQLCraft\Connection\CallbackCredentialProvider` | ✅ **EXISTS** | Already implemented |
| `SQLCraft\Connection\ArrayCredentialProvider` | ✅ **EXISTS** | Already implemented |
| `SQLCraft\Connection\EnvCredentialProvider` | ✅ **EXISTS** | Already implemented |

### 3.3 Built-In Extensions

**Priority: LOW (P2 — convenience, not blocking)**

SQLCraft equivalents of popular Adminer plugins:

| Extension | Adminer Plugin Equivalent | Implementation Status |
|---|---|---|
| `QueryLogger` | `sql-log.php` | ❌ Missing |
| `SlowQueryDetector` | (not in Adminer) | ❌ Missing (event exists) |
| `ReadOnlyGuard` | (not in Adminer) | ❌ Missing |
| `TenantScopingInterceptor` | (not in Adminer) | ❌ Missing |
| `DatabaseFilterExtension` | `database-hide.php` | ❌ Missing |
| `BackwardKeysExtension` | `backward-keys.php` | ❌ Missing |
| `JsonFormatWriter` | `dump-json.php` | ❌ Missing |
| `XmlFormatWriter` | `dump-xml.php` | ❌ Missing |
| `PhpFormatWriter` | `dump-php.php` | ❌ Missing |

### 3.4 API Stability Annotations

**Priority: MEDIUM (P1 — API contract clarity)**

| Task | Status | Notes |
|---|---|---|
| Tag all public extension interfaces with `@api` | ❌ Missing | `CredentialProviderInterface`, `FormatWriterInterface`, etc. |
| Tag internal classes/interfaces with `@internal` | ❌ Partial | Only `MetadataFactoryInterface` is marked |
| Document SemVer policy in extension guide | ❌ Missing | Interface evolution policy from plan17 §8.1 |
| Add `@api` to stable DTOs | ❌ Missing | `ColumnMeta`, `TableStatus`, `ForeignKeyMeta`, etc. |

### 3.5 Documentation

**Priority: HIGH (P0 — extension authors need guidance)**

| Document | Status | Notes |
|---|---|---|
| Extension Author Guide | ❌ Missing | How to write/register extensions |
| Built-in Extension Examples | ❌ Missing | Reference implementations |
| API Stability Tier Documentation | ❌ Missing | What interfaces are stable, what are internal |
| Migration Guide from Adminer Plugins | ❌ Missing | Hook-by-hook mapping |

---

## 4. Implementation Phases

### Phase 0: Foundation (P0 — Blocking)

**Goal:** Core extension infrastructure that other extensions depend on

1. `AbstractPlatformDecorator` — typed base class for platform decoration
2. `CredentialProviderChain` — composite credential provider
3. `ExtensionBundle` / `ServiceProviderInterface` pattern
4. `@api` / `@internal` annotations on all public extension interfaces

**Deliverables:**
- `src/Extension/AbstractPlatformDecorator.php`
- `src/Extension/CredentialProviderChain.php`
- `src/Extension/ExtensionBundle.php`
- `src/Extension/ServiceProviderInterface.php`
- PHPDoc annotations on all `Contracts\` interfaces

**Estimated effort:** 3-5 days

### Phase 1: Default Implementations (P1 — Usability)

**Goal:** Provide sensible default implementations for common extension interfaces

1. `InMemoryQueryHistory`
2. `InMemoryMetadataCache` (PSR-16 compatible)
3. `SchemaFilterInterface` + `DatabaseHideFilter`

**Deliverables:**
- `src/Execution/InMemoryQueryHistory.php`
- `src/Execution/NullQueryHistory.php`
- `src/Schema/InMemoryMetadataCache.php`
- `src/Contracts/Schema/SchemaFilterInterface.php`
- `src/Schema/DatabaseHideFilter.php`

**Estimated effort:** 2-3 days

### Phase 2: Built-In Extensions (P2 — Convenience)

**Goal:** SQLCraft-native extension implementations for common use cases

1. `QueryLogger` (PSR-3 compatible)
2. `ReadOnlyGuard` (veto writes)
3. `SlowQueryDetector`
4. `TenantScopingInterceptor`
5. `JsonFormatWriter`, `XmlFormatWriter`, `PhpFormatWriter`

**Deliverables:**
- `src/Extension/QueryLogger.php`
- `src/Extension/ReadOnlyGuard.php`
- `src/Extension/SlowQueryDetector.php`
- `src/Extension/TenantScopingInterceptor.php`
- `src/Export/JsonFormatWriter.php`
- `src/Export/XmlFormatWriter.php`
- `src/Export/PhpFormatWriter.php`

**Estimated effort:** 5-7 days

### Phase 3: Documentation (P0 — Critical for adoption)

**Goal:** Complete extension author documentation

1. Extension Author Guide (how to write extensions)
2. Built-in Extension Reference
3. API Stability Tier Documentation
4. Adminer Plugin Migration Guide

**Deliverables:**
- `docs/development/extension-guide.md`
- `docs/api/extension-interfaces.md`
- `docs/api/stability-tiers.md`
- `docs/development/adminer-migration.md`

**Estimated effort:** 3-4 days

---

## 5. Testing Strategy

Each phase must include:

1. **Unit tests** for all new classes (PHPUnit, 80%+ coverage)
2. **Integration tests** for extension registration and lifecycle
3. **Contract tests** for interface implementations (PSR-14, PSR-16 compliance)
4. **Example extensions** demonstrating common patterns

**Test pyramid:**
- Unit: 70% of tests
- Integration: 25% of tests
- Example/smoke: 5% of tests

---

## 6. Success Criteria

The extension system implementation is **COMPLETE** when:

✅ All P0 (Phase 0) components are implemented and tested
✅ All P1 (Phase 1) default implementations exist
✅ Extension Author Guide is published
✅ At least 3 built-in extensions demonstrate the three extension mechanisms
✅ All public extension interfaces are tagged `@api`
✅ All internal classes are tagged `@internal`
✅ Migration guide from Adminer plugins exists
✅ Third-party developers can write and register extensions without reading SQLCraft internals

---

## 7. Related Documents

- `17-plugin-system.md` — Design specification (comprehensive)
- `01-extension-interfaces.md` — Detailed interface specifications
- `02-format-registry.md` — FormatRegistry API surface
- `03-platform-decorator.md` — AbstractPlatformDecorator implementation
- `04-query-interceptors.md` — Query interception patterns
- `05-credential-chain.md` — CredentialProviderChain implementation
- `06-built-in-extensions.md` — Built-in extension catalog
- `07-stability-annotations.md` — @api/@internal policy
- `08-implementation-order.md` — Detailed phase breakdown

---

## 8. Non-Goals (Explicit Exclusions)

The following are explicitly **NOT** part of this implementation:

❌ **UI/rendering hooks** — SQLCraft has no HTML/HTTP layer
❌ **Auto-discovery plugin directories** — explicit DI registration only
❌ **Magic `__call` dispatch** — all extension points are typed interfaces
❌ **Runtime reflection for capability detection** — use `instanceof` and type system
❌ **Single monolithic `Plugin` base class** — use mechanism-specific interfaces
❌ **Web-application concerns** — sessions, cookies, brute-force protection, form processing

These are Adminer-specific patterns that SQLCraft deliberately avoids (see plan17 §1, §9).

---

**Next Steps:** Review phase priorities with stakeholders, then proceed to Phase 0 implementation starting with `AbstractPlatformDecorator` (document 03).
