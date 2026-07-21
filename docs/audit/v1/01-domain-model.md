# 01 — Domain Model & Package Structure Audit

> **Status:** Final
> **Audit date:** 2026-07-21
> **Baseline:** `master @ 6d50506`
> **Plans reviewed:** `05-domain-model.md`, `06-package-architecture.md`, `19-package-structure.md`
> **Implementation reviewed:** `src/DTO/`, `src/ValueObjects/`, `src/Collections/`, `src/Exceptions/`, `src/Support/`, `src/Contracts/`

---

## 1. Gaps

- **MODERATE — INSERT/UPDATE/DELETE query builders missing.** Plan 05 §7 (`QueryBuilder` → "Fluent SELECT/INSERT/UPDATE/DELETE construction") and plan 06 §3 (Query context owns "Fluent SELECT/INSERT/UPDATE/DELETE builder") promise write-side builders; plan 07 (lines 50, 367) and plan 00 (line 98: "Type-safe SELECT/INSERT/UPDATE/DELETE builders, pagination, FK navigation") repeat the promise. Only `SelectQuery` exists in `src/Query/` (`ColumnSelection`, `OrderByClause`, `WhereCondition`, `Paginator`, `Page`, `PaginationParams`, `SelectQueryRenderer`, `StatementSplitter`). Plan 12 silently narrowed scope to SELECT; FK navigation is also absent. Consumers must fall back to raw SQL for writes. (Cross-ref: [05](05-query-engine.md).)

- **MINOR — `SQLCraft\Utilities\` context never created.** Plan 06 §3 defines it ("Pure helpers — pagination math, identifier sanitisation") with its own dependency rule (Rule 20: Utilities → Support only), and plan 19 §2/§4 lists `src/Utilities/` in the tree and PSR-4 map. The helpers landed elsewhere instead: pagination math in `src/Query/`, identifier sanitisation in `src/Security/IdentifierQuoter.php`.

- **MINOR — `resources/` placeholder directory absent.** Plan 19 §8 decided the capability matrix stays in code but said `resources/` "is kept in the tree now because PSR-4 packages conventionally reserve `resources/`". The directory does not exist at the repo root. The substantive decision (matrix in code) is honored; only the placeholder is missing.

## 2. Drift

- **MINOR (plan-internal inconsistency; code follows the more detailed plan) — `CapabilityNotSupportedException` placement.** Plan 05 §9's exception hierarchy diagram places it under `Exceptions\`. Plan 09 §10 gives its full code in namespace `SQLCraft\Capabilities`, and the implementation matches 09 §10 exactly (`src/Capabilities/CapabilityNotSupportedException.php`, extending `Exceptions\CapabilityException`). The code is correct; plan 05's diagram is the outlier. (Cross-ref: [02](02-driver-platform-capabilities.md).)

- **MINOR — `CapabilityException` is abstract.** Plan 05 §9 sketches it as a concrete hierarchy node; implementation declares `abstract class CapabilityException extends SQLCraftException` (`src/Exceptions/CapabilityException.php:7`). Functionally stricter, no consumer impact.

- **MINOR — DTO naming: `RoutineParamMeta` → `RoutineParameter`.** Plan 05/11 §3.8 names `RoutineParamMeta`; implementation ships `src/DTO/RoutineParameter.php`. (Also noted in [04](04-schema-ddl.md).)

## 3. Extras (implemented, not in plans 05/06/19)

- **Value Objects:** `ConnectionParameters` (planned in doc 10, not 05), `DefaultValueKind` (discriminator enum backing `DefaultValue` — realizes 05 §3.4's "distinguishes NULL, '', literal, expression, sequence" intent), `Privilege` (security work, plan 15).
- **DTOs:** `CheckConstraintMeta`, `DatabaseMeta`, `ExplainResult`, `IndexColumnMeta`, `PartitionInfo`, `ProcessMeta`, `QueryWarning`, `RoutineParameter`, `SchemaMeta`, `SequenceMeta`, `ServerInfo`, `UserMeta`, `ViewMeta` — all covered by later plans (11, 12, 15). **`BackwardKeyMeta` is an orphan**: defined in `src/DTO/BackwardKeyMeta.php` but referenced nowhere else; the plan's "backward key" concept is served by `getReferencingKeys()` returning `ForeignKeyCollection`.
- **Collections (21 total):** beyond the planned `ColumnCollection`/`IndexCollection`/`TableCollection`/`ForeignKeyCollection` (05 §6): `CharsetCollection`, `CheckConstraintCollection`, `CollationCollection`, `DatabaseCollection`, `PartitionCollection`, `PrivilegeCollection`, `ProcessCollection`, `QualifiedNameCollection`, `RoutineCollection`, `SchemaCollection`, `SequenceCollection`, `TriggerCollection`, `TypeCollection`, `UserCollection`, `ViewCollection`, `WarningCollection` — one per inspector return type.
- **Exceptions (four beyond plan 05 §9):** `ConnectionClosedException`, `InvalidOperatorException`, `OperationCancelledException`, `StreamingResultException` — from the events/cancellation, security, and streaming work (plans 15, 16).

## 4. Faithful to Plan

- **All VOs from plan 05 §3 exist** with the planned shape: `Identifier` (rejects empty + null bytes), `QualifiedName` (object/schema/catalog tuple), `DataType` (name/length/precision/scale/unsigned/collation/charset), `DefaultValue`, and the enums `ForeignKeyAction`, `TriggerTiming`, `TriggerEvent`, `RoutineDirection`, `IndexType`, plus `Charset`, `Collation`, `Engine`, `ServerVersion`.
- **All core DTOs from plan 05 §4/§8 exist:** `ColumnMeta` (including `onUpdate`, `privileges`, `origName`, `defaultConstraintName` per §4.1's Adminer mapping), `TableStatus` (§4.2 fields), `ForeignKeyMeta` (§4.3 fields), `IndexMeta`, `TriggerMeta`, `RoutineMeta`, `ExecutionResult`.
- **Exception hierarchy intact per plan 05 §9:** every planned node exists — `SQLCraftException` base; `ConnectionException` → `ConnectionFailedException`/`AuthenticationException`/`ConnectionLostException`; `QueryException` → `SyntaxErrorException`/`ConstraintViolationException` → `UniqueConstraintException`/`ForeignKeyConstraintException`, `DeadlockException`; `MetadataException` → `ObjectNotFoundException`; `CapabilityException`; `SecurityException` → `InsufficientPrivilegesException`; `DriverException` → `DriverNotFoundException`/`DriverMisconfiguredException`; `ImportExportException` → `ImportFailedException`/`ExportFailedException`.
- **`Support` is a true leaf node** per plan 06 Rule 6: `ArrayUtil`, `SecretRedactor`, `StringUtil`, `TypeUtil` — zero `use SQLCraft\...` imports (verified by grep).
- **Conventions honored:** `declare(strict_types=1)`, `final readonly` classes, constructor promotion throughout.
- **PSR-4 namespace map matches plan 19 §4** for every existing context; `Contracts\` subdivides by module name (`Contracts/Connection`, `Contracts/Driver`, `Contracts/Platform`, `Contracts/Metadata`, `Contracts/Schema`, `Contracts/DDL`, `Contracts/Query`, `Contracts/Execution`, `Contracts/Import`, `Contracts/Export`, `Contracts/Security`, `Contracts/Capabilities`, `Contracts/Events`) exactly as specified.
- **`MetadataFactoryInterface` hydration split** (plan 05 §8) realized: per-platform factories in `src/Metadata/`, dialect SQL on platforms, orchestration in services.

## 5. Summary

The domain model is the most plan-faithful area of the codebase: every planned VO, DTO, collection shape, and exception node exists, conventions are uniformly honored, and the dependency rules (Support as leaf, Contracts as ports) hold. The only substantive gap charged to this area is the missing INSERT/UPDATE/DELETE builders promised by plans 05/06/07/00 (detail in [05](05-query-engine.md)); the rest are minor placement/naming items and additive extras covered by later plans.
