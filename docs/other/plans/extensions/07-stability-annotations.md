# API Stability Annotations and SemVer Policy

> **Authoritative replacement:** `docs/other/plans/extensions-revised/04-implementation-handoff.md` and `03-verification.md`. This document is retained for history and is not an active implementation requirement.


> **Status:** SUPERSEDED — historical reference only
> **Phase:** 0 (Foundation — must land before v1.0)
> **Scope:** PHPDoc `@api` / `@internal` tags, SemVer tier documentation, interface evolution policy

---

## 1. The Problem

`17-plugin-system.md §8` defines three stability tiers: **Stable public extension interfaces**, **Stable public DTOs/VOs**, and **Internal (`@internal`)**. But as of 2026-07-22, none of the actual source files carry these annotations systematically. Extension authors cannot tell which interfaces they can safely implement without reading the architecture documents.

This plan specifies exactly which files need `@api` vs `@internal`, and adds the PHPDoc changes needed.

---

## 2. Annotation Conventions

| Annotation | Meaning |
|---|---|
| `@api` | Public API: SemVer-stable. Changing method signatures is a breaking change requiring a major version bump. New methods are **never** added to an existing `@api` interface — they go in a new sub-interface instead. |
| `@internal` | Implementation detail: no stability guarantee. May change in any release including patches. Extension authors must NOT implement, extend, or depend on these classes. |
| _(no annotation)_ | Semi-public: stable for use (calling methods), but implementing the interface or extending the class is not guaranteed stable. Used for higher-level services like `DatabaseSession`. |

---

## 3. Files That Need `@api`

### 3.1 Core Extension Interfaces — `src/Contracts/`

All of the following need `@api` on the interface docblock:

**Connection:**
- `src/Contracts/Connection/ConnectionInterface.php`
- `src/Contracts/Connection/CredentialProviderInterface.php`
- `src/Contracts/Connection/ResultInterface.php`
- `src/Contracts/Connection/PreparedStatementInterface.php`

**Driver:**
- `src/Contracts/Driver/DriverInterface.php`

**Platform:**
- `src/Contracts/Platform/PlatformInterface.php`
- `src/Contracts/Platform/DdlDialectInterface.php`
- `src/Contracts/Platform/IntrospectionDialectInterface.php`
- `src/Contracts/Platform/PaginationInterface.php`
- `src/Contracts/Platform/QuotingInterface.php`
- `src/Contracts/Platform/TypeMapperInterface.php`

**Metadata (all Inspector interfaces):**
- `src/Contracts/Metadata/ServerInspectorInterface.php`
- `src/Contracts/Metadata/DatabaseInspectorInterface.php`
- `src/Contracts/Metadata/TableInspectorInterface.php`
- `src/Contracts/Metadata/ColumnInspectorInterface.php`
- `src/Contracts/Metadata/ForeignKeyInspectorInterface.php`
- `src/Contracts/Metadata/IndexInspectorInterface.php`
- `src/Contracts/Metadata/ViewInspectorInterface.php`
- `src/Contracts/Metadata/RoutineInspectorInterface.php`
- `src/Contracts/Metadata/TriggerInspectorInterface.php`
- `src/Contracts/Metadata/SequenceInspectorInterface.php`
- `src/Contracts/Metadata/UserInspectorInterface.php`
- `src/Contracts/Metadata/PrivilegeInspectorInterface.php`
- `src/Contracts/Metadata/CheckConstraintInspectorInterface.php`
- `src/Contracts/Metadata/MetadataCacheInterface.php`

**Import/Export:**
- `src/Contracts/Export/FormatWriterInterface.php`
- `src/Contracts/Export/SinkInterface.php`
- `src/Contracts/Export/ExportSourceInterface.php`
- `src/Contracts/Export/ForeignKeyExportSourceInterface.php`
- `src/Contracts/Import/FormatReaderInterface.php`
- `src/Contracts/Import/ImportSourceInterface.php`
- `src/Contracts/Import/CsvImporterInterface.php`
- `src/Contracts/Import/ImporterInterface.php`
- `src/Contracts/Export/ExporterInterface.php`

**Execution:**
- `src/Contracts/Execution/QueryExecutorInterface.php`
- `src/Contracts/Execution/QueryHistoryInterface.php`
- `src/Contracts/Execution/TransactionManagerInterface.php`
- `src/Contracts/Execution/StatementSplitterInterface.php`

**Events:**
- `src/Contracts/Events/ListenableProviderInterface.php` *(new from plan 01)*

### 3.2 Stable DTOs and Value Objects

All public constructor properties in these classes are part of the stable API:

- `src/DTO/ColumnMeta.php`
- `src/DTO/TableStatus.php`
- `src/DTO/ForeignKeyMeta.php`
- `src/DTO/IndexMeta.php`
- `src/DTO/IndexColumnMeta.php`
- `src/DTO/TriggerMeta.php`
- `src/DTO/ViewMeta.php`
- `src/DTO/RoutineMeta.php`
- `src/DTO/RoutineParameter.php`
- `src/DTO/DatabaseMeta.php`
- `src/DTO/SchemaMeta.php`
- `src/DTO/ServerInfo.php`
- `src/DTO/UserMeta.php`
- `src/DTO/ExplainResult.php`
- `src/DTO/ExecutionResult.php`
- `src/DTO/ProcessMeta.php`
- `src/DTO/SequenceMeta.php`
- `src/DTO/BackwardKeyMeta.php`
- `src/DTO/CheckConstraintMeta.php`
- `src/DTO/PartitionInfo.php`
- `src/DTO/QueryWarning.php`
- `src/ValueObjects/ConnectionParameters.php`
- `src/ValueObjects/Credential.php` *(verify exists)*
- `src/ValueObjects/ServerVersion.php`

### 3.3 Event Classes — Stable for Listener Consumption

All public properties and methods on event classes used in listeners:

- All classes in `src/Events/` that are non-abstract and non-internal
- In particular: all `*Event.php` classes, `BeforeQueryExecuted.php`, `BeforeConnectionOpened.php`, etc.

### 3.4 Capabilities

- `src/Capabilities/Capability.php` (the enum cases are the stable API)
- `src/Capabilities/CapabilitySet.php` (the public query methods)

---

## 4. Files That Need `@internal`

### 4.1 Internal Metadata Infrastructure

Per `17-plugin-system.md §8`:

- `src/Metadata/AbstractMetadataFactory.php` — `@internal` (not for extension authors)
- Any `*MetadataFactory.php` files in driver subdirectories

### 4.2 PDO Internals

- `src/Connection/PdoConnection.php` — `@internal`
- `src/Connection/PdoPreparedStatement.php` — `@internal`
- `src/Connection/PdoConnectionFactory.php` — `@internal`
- `src/Connection/PdoExceptionTranslator.php` — `@internal`

### 4.3 Platform Internals

- `src/Platform/AbstractPlatform.php` — `@internal` (not for extension authors; they use `AbstractPlatformDecorator` instead)
- All `src/Platform/MySQL/`, `src/Platform/PostgreSQL/` etc. subdirectory classes — `@internal`
- All `src/Driver/MySQL/`, `src/Driver/PostgreSQL/` etc. concrete driver subdirectory classes — `@internal`

### 4.4 DDL Implementation Classes

- `src/DDL/Definition/` — all `@internal`

### 4.5 Schema Internals

- `src/Schema/CacheInvalidationListener.php` — `@internal`
- `src/Schema/SchemaManagerFactory.php` — semi-public (no `@api`, no `@internal`; used by consumers but not extensible)

---

## 5. PHPDoc Format

The annotation goes on the class/interface docblock, immediately before the `class`/`interface` keyword:

```php
/**
 * Supplies credentials for database connections.
 *
 * Implement this interface to read credentials from Vault, AWS Secrets Manager,
 * environment variables, or any other source.
 *
 * @api
 */
interface CredentialProviderInterface
```

For `@internal`:

```php
/**
 * @internal
 */
final class PdoExceptionTranslator
```

---

## 6. Interface Evolution Policy (Enforcement)

From `17-plugin-system.md §8.1`, enforced by these rules:

1. **Adding a method to a `@api` interface = major version bump.** No exceptions.
2. **New optional behavior goes in a new interface**, checked via `instanceof` at the call site.
3. **`method_exists()` probing on `@api` interfaces is forbidden** in SQLCraft internals. If a method might or might not exist, the new-interface + `instanceof` pattern is required.
4. **Internal-only interfaces (marked `@internal`) may change in any release** — including adding/removing methods.

### Enforcement via Static Analysis

Add a custom PHPStan rule (or Deptrac constraint) that:
- Flags any `method_exists($obj, ...)` call in `src/` (use `instanceof` instead)
- Flags `extends` on `@internal` classes from outside the `SQLCraft` namespace

---

## 7. Priority Bands for PSR-14 Listeners

Document the reserved priority bands for event listeners to prevent ordering conflicts:

| Priority range | Meaning | Who uses it |
|---|---|---|
| `>= 200` | System critical (e.g., authentication veto) | SQLCraft internals only |
| `100–199` | High — security/read-only guards | `ReadOnlyGuard`, security extensions |
| `10–99` | Normal — business logic | Application listeners |
| `0–9` (default) | Low — logging, metrics, audit | `QueryLogger`, `ConnectionTracer` |
| `< 0` | Post-processing | Cleanup, metrics flushing |

Document these bands in a `PRIORITY_BANDS.md` or inline in the extension author guide.

---

## 8. Checklist — Required Before v1.0

- [ ] All interfaces in `src/Contracts/` carry `@api` or `@internal`
- [ ] All concrete classes in `src/Platform/`, `src/Driver/` subdirs carry `@internal`
- [ ] `AbstractPlatform.php` carries `@internal`
- [ ] All `*MetadataFactory.php` carry `@internal`
- [ ] `PdoConnection`, `PdoPreparedStatement`, `PdoConnectionFactory`, `PdoExceptionTranslator` carry `@internal`
- [ ] All DTOs in `src/DTO/` and `src/ValueObjects/` carry `@api`
- [ ] All event classes in `src/Events/` used as listener targets carry `@api`
- [ ] `Capability` enum and `CapabilitySet` carry `@api`
- [ ] PHPStan rule in place to reject `method_exists()` on typed objects
- [ ] Priority band documentation written and linked from extension guide

---

## 9. File Summary — Annotation-only Changes (No Logic Change)

| Action | Files |
|---|---|
| Add `@api` docblock | ~50 interface/DTO/VO/event files listed in §3 |
| Add `@internal` docblock | ~20 concrete implementation files listed in §4 |
| New: `PRIORITY_BANDS.md` or inline in extension guide | `docs/development/extension-guide.md` |
| New: PHPStan custom rule | `tools/phpstan/NoMethodExistsOnTypedObjectRule.php` |
