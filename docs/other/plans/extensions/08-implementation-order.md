# Implementation Order and Phase Breakdown

> **Authoritative replacement:** `docs/other/plans/extensions-revised/04-implementation-handoff.md` and `03-verification.md`. This document is retained for history and is not an active implementation requirement.


> **Status:** SUPERSEDED — historical reference only
> **Purpose:** Detailed, dependency-ordered implementation schedule for the extension system
> **Est. Total Effort:** 18-23 days (4 developer-weeks)

---

## Phase 0: Foundation (P0 — Blocking)

**Duration:** 5-7 days
**Blocks:** All other phases
**Goal:** Core infrastructure that other extensions depend on

### 0.1 Extension Interfaces (Day 1-2)

**Files:**
- `src/Extension/ServiceProviderInterface.php`
- `src/Extension/ExtensionBundle.php`
- `src/Contracts/Events/ListenableProviderInterface.php`
- `src/Events/SimpleListenerProvider.php` (modify: add `implements ListenableProviderInterface`)

**Tests:**
- `tests/Extension/ServiceProviderInterfaceTest.php`
- `tests/Extension/ExtensionBundleTest.php`

**Definition of Done:**
- [ ] `ServiceProviderInterface` compiles and PHPStan passes at level 10
- [ ] `ExtensionBundle` abstract base class compiles
- [ ] `ListenableProviderInterface` extends PSR-14 `ListenerProviderInterface`
- [ ] `SimpleListenerProvider` declares `implements ListenableProviderInterface`
- [ ] Unit tests green, 80%+ coverage

**Related:** `01-extension-interfaces.md`

---

### 0.2 Platform Decoration Helpers (Day 3-4)

**Files:**
- `src/Extension/AbstractPlatformDecorator.php` ⚠️ **Largest file — enumerate all ~40 interface methods**
- `src/Extension/AbstractDriverDecorator.php`
- `src/Extension/Platform/ReadOnlyPlatformDecorator.php`
- `src/Extension/Platform/CapabilityOverridePlatformDecorator.php`

**Tests:**
- `tests/Extension/AbstractPlatformDecoratorTest.php`
- `tests/Extension/AbstractDriverDecoratorTest.php`

**Definition of Done:**
- [ ] `AbstractPlatformDecorator` delegates all methods from `PlatformInterface` + 5 sub-interfaces
- [ ] `AbstractDriverDecorator` delegates all methods from `DriverInterface`
- [ ] Concrete decorators (`ReadOnlyPlatformDecorator`, `CapabilityOverridePlatformDecorator`) compile
- [ ] Unit tests verify delegation and override behavior
- [ ] PHPStan/Psalm level 10 pass

**Related:** `03-platform-decorator.md`

**⚠️ Risk:** Enumerating all platform interface methods is tedious and error-prone. Use script generation or code review checklist to verify completeness against source interfaces.

---

### 0.3 Credential & History Defaults (Day 5)

**Files:**
- `src/Connection/CredentialProviderChain.php`
- `src/Execution/InMemoryQueryHistory.php`
- `src/Execution/NullQueryHistory.php`

**Tests:**
- `tests/Connection/CredentialProviderChainTest.php`
- `tests/Execution/InMemoryQueryHistoryTest.php`
- `tests/Execution/NullQueryHistoryTest.php`

**Definition of Done:**
- [ ] `CredentialProviderChain` tries providers in order, throws on all-fail with last exception as `previous`
- [ ] `InMemoryQueryHistory` records and returns entries, respects `$maxPerDatabase`
- [ ] `NullQueryHistory` discards all entries
- [ ] Unit tests green, edge cases covered (empty provider list, bound overflow, etc.)

**Related:** `04-query-interceptors.md §3`

---

### 0.4 Schema Filter Interface (Day 6)

**Files:**
- `src/Contracts/Schema/SchemaFilterInterface.php`
- `src/Schema/PrefixDatabaseFilter.php`
- `src/Schema/TenantSchemaFilter.php`
- `src/Schema/NullSchemaFilter.php`

**Tests:**
- `tests/Schema/PrefixDatabaseFilterTest.php`
- `tests/Schema/TenantSchemaFilterTest.php`

**Definition of Done:**
- [ ] `SchemaFilterInterface` compiles with `filterDatabases()` / `filterTables()` methods
- [ ] `PrefixDatabaseFilter` hides databases by prefix
- [ ] `TenantSchemaFilter` restricts to tenant-prefixed databases
- [ ] `NullSchemaFilter` (no-op passthrough) exists
- [ ] Unit tests verify filtering logic

**Related:** `04-query-interceptors.md §4`

---

### 0.5 API Stability Annotations (Day 7)

**Files:** ~70 source files across `src/Contracts/`, `src/DTO/`, `src/ValueObjects/`, `src/Events/`, `src/Capabilities/`

**Action:**
- Add `@api` docblock to ~50 files (all extension interfaces, DTOs, VOs, events)
- Add `@internal` docblock to ~20 files (metadata factories, PDO internals, concrete platforms)

**Tests:** None (annotation-only change)

**Definition of Done:**
- [ ] All files listed in `07-stability-annotations.md §3` have `@api` docblock
- [ ] All files listed in `07-stability-annotations.md §4` have `@internal` docblock
- [ ] PHPStan custom rule in place to reject `method_exists()` on typed objects (optional, nice-to-have)
- [ ] PR reviewed for correct placement of annotations

**Related:** `07-stability-annotations.md`

**⚠️ Risk:** This is tedious and error-prone. Use a checklist or script to verify all files are tagged.

---

**Phase 0 Exit Criteria:**
- [ ] All P0 files compile without errors
- [ ] PHPStan/Psalm level 10 pass
- [ ] Unit tests green, 80%+ coverage on new code
- [ ] `make build` passes (cs-fixer, deptrac, tests)
- [ ] Extension interfaces are `@api`, internals are `@internal`

---

## Phase 1: Default Implementations (P1 — Usability)

**Duration:** 3-4 days
**Depends on:** Phase 0
**Goal:** Sensible default implementations for common use cases

### 1.1 Format Implementations (Day 8-9)

**Files:**
- `src/Export/JsonFormatWriter.php`
- `src/Export/XmlFormatWriter.php`
- `src/Export/PhpFormatWriter.php`
- `src/Export/ZipArchiveSink.php`
- `src/Export/StringBufferSink.php`
- `src/Import/StringImportSource.php`
- `src/Import/UrlImportSource.php`

**Tests:**
- `tests/Export/JsonFormatWriterTest.php`
- `tests/Export/XmlFormatWriterTest.php`
- `tests/Export/PhpFormatWriterTest.php`
- `tests/Export/StringBufferSinkTest.php`
- `tests/Import/StringImportSourceTest.php`

**Definition of Done:**
- [ ] `JsonFormatWriter` produces valid JSON (verified via `json_decode()`)
- [ ] `XmlFormatWriter` produces valid XML with proper escaping
- [ ] `PhpFormatWriter` produces syntactically valid PHP (verified via `token_get_all()` or execution)
- [ ] `ZipArchiveSink` produces a valid ZIP file (verified via `\ZipArchive::open()` read)
- [ ] `StringBufferSink` accumulates writes, supports `getContents()` / `reset()`
- [ ] All writers registered via `FormatRegistry::registerWriter()` appear in `getSupportedWriteFormats()`

**Related:** `02-format-registry.md §2, §3`

---

### 1.2 Metadata Cache Default (Day 10)

**Files:**
- `src/Schema/InMemoryMetadataCache.php`

**Tests:**
- `tests/Schema/InMemoryMetadataCacheTest.php`

**Definition of Done:**
- [ ] Implements `MetadataCacheInterface`
- [ ] `remember()` caches by key, respects TTL
- [ ] `invalidateTable()` / `invalidateDatabase()` / `clear()` work as specified
- [ ] PSR-16 compatible (if feasible; otherwise document deviation)
- [ ] Unit tests verify cache hit/miss, TTL expiry, invalidation

**Related:** `04-query-interceptors.md` (mentions default cache implementations)

---

### 1.3 SQLCraftFactory Integration (Day 11)

**Files:**
- `src/SQLCraftFactory.php` (modify: add `withServiceProvider()` method)

**Tests:**
- `tests/SQLCraftFactoryTest.php` (modify: add provider registration tests)

**Definition of Done:**
- [ ] `SQLCraftFactory::withServiceProvider(ServiceProviderInterface): static` method added
- [ ] Method is immutable (returns clone)
- [ ] Providers are applied during `session()` / internal `applyProviders()` call
- [ ] Integration test: register a driver via provider, verify it appears in registry
- [ ] Integration test: register a format via provider, verify it appears in `FormatRegistry`

**Related:** `01-extension-interfaces.md §4`

---

**Phase 1 Exit Criteria:**
- [ ] All format writers produce valid output (validated by parsers/openers)
- [ ] `InMemoryMetadataCache` passes PSR-16 contract tests (if applicable)
- [ ] `SQLCraftFactory::withServiceProvider()` integration tests green
- [ ] `make build` passes

---

## Phase 2: Built-In Extensions (P2 — Convenience)

**Duration:** 5-7 days
**Depends on:** Phase 0, Phase 1
**Goal:** SQLCraft-native extension implementations demonstrating all three mechanisms

### 2.1 Query & Connection Observers (Day 12-13)

**Files:**
- `src/Extension/QueryLogger.php`
- `src/Extension/ConnectionTracer.php`
- `src/Extension/SlowQueryDetector.php`

**Tests:**
- `tests/Extension/QueryLoggerTest.php`
- `tests/Extension/ConnectionTracerTest.php`
- `tests/Extension/SlowQueryDetectorTest.php`

**Definition of Done:**
- [ ] `QueryLogger` logs via PSR-3 at correct levels (debug/error/warning)
- [ ] `ConnectionTracer` logs all connection lifecycle events
- [ ] `SlowQueryDetector` fires callback only above threshold
- [ ] Unit tests with mock loggers verify output
- [ ] Integration test: register via `ExtensionBundle`, verify events reach listeners

**Related:** `06-built-in-extensions.md §2, §7, §4`

---

### 2.2 Security Extensions (Day 14-15)

**Files:**
- `src/Extension/ReadOnlyGuard.php`
- `src/Extension/TenantScopingInterceptor.php`

**Tests:**
- `tests/Extension/ReadOnlyGuardTest.php`
- `tests/Extension/TenantScopingInterceptorTest.php`

**Definition of Done:**
- [ ] `ReadOnlyGuard` cancels all write operations (INSERT/UPDATE/DELETE/DDL)
- [ ] `ReadOnlyGuard` allows SELECT through
- [ ] `TenantScopingInterceptor` rewrites SELECT with tenant WHERE clause
- [ ] Unit tests verify SQL rewriting logic
- [ ] Integration test: write query throws after `ReadOnlyGuard` registration

**Related:** `06-built-in-extensions.md §3, §5`

**⚠️ Risk:** `TenantScopingInterceptor`'s naive SQL rewriting is not production-safe. Document limitations clearly and consider marking it `@experimental` or providing a parser-based alternative in a future phase.

---

**Phase 2 Exit Criteria:**
- [ ] All built-in extensions compile and pass unit tests
- [ ] At least one integration test per extension verifies end-to-end behavior
- [ ] `make build` passes

---

## Phase 3: Documentation (P0 — Critical for Adoption)

**Duration:** 4-5 days
**Can run in parallel with Phase 2**
**Goal:** Complete extension author documentation

### 3.1 Extension Author Guide (Day 16-18)

**Files:**
- `docs/development/extension-guide.md` (new)

**Sections:**
- Introduction to the three extension mechanisms
- How to implement each mechanism (with code examples)
- How to register extensions via `ServiceProviderInterface` / `ExtensionBundle`
- How to use `AbstractPlatformDecorator` / `AbstractDriverDecorator`
- API stability tiers (`@api` vs `@internal`)
- Priority bands for event listeners
- Common patterns (credential chains, query interception, schema filtering)
- Testing extensions

**Definition of Done:**
- [ ] Guide is >3000 words with complete code examples
- [ ] All three mechanisms have worked examples
- [ ] Links to relevant API docs and plan docs
- [ ] Reviewed for accuracy and completeness

---

### 3.2 API Reference & Stability Tiers (Day 19)

**Files:**
- `docs/api/extension-interfaces.md` (new)
- `docs/api/stability-tiers.md` (new)

**Definition of Done:**
- [ ] `extension-interfaces.md` lists all `@api` extension interfaces with one-line descriptions
- [ ] `stability-tiers.md` documents SemVer policy, interface evolution, `@api` vs `@internal`
- [ ] Cross-references to PHPDoc `@api` / `@internal` tags

---

### 3.3 Adminer Migration Guide (Day 20)

**Files:**
- `docs/development/adminer-migration.md` (new)

**Sections:**
- Adminer plugin model vs SQLCraft extension model
- Hook-by-hook mapping table (from `17-plugin-system.md §6`)
- Example migrations: `sql-log.php` → `QueryLogger`, `database-hide.php` → `SchemaFilterInterface`
- What is NOT migrated (UI hooks, web-app concerns)

**Definition of Done:**
- [ ] Complete mapping table from `17-plugin-system.md §6` included
- [ ] At least 3 concrete migration examples with before/after code
- [ ] Reviewed for accuracy

---

**Phase 3 Exit Criteria:**
- [ ] Extension Author Guide is complete and published
- [ ] API reference and stability tiers documented
- [ ] Adminer migration guide is complete
- [ ] All docs reviewed and linked from main README

---

## Phase 4: Integration & Polish (P1 — Quality Gate)

**Duration:** 1-2 days
**Depends on:** All prior phases
**Goal:** End-to-end integration, final polish

### 4.1 Full-Stack Integration Tests (Day 21)

**Tests:**
- `tests/Integration/ExtensionSystemIntegrationTest.php`

**Scenarios:**
- Register a third-party driver via `ServiceProviderInterface`, connect, query
- Register a format writer, export, verify output
- Register query logger, execute query, verify log entry
- Register read-only guard, attempt write, verify cancellation
- Register schema filter, list databases, verify filtered result

**Definition of Done:**
- [ ] All 5+ integration scenarios pass
- [ ] No PHPStan/Psalm errors
- [ ] Deptrac architectural boundaries verified (no Contract → concrete dependencies)

---

### 4.2 Example Extensions Package (Day 22)

**Optional but recommended:**

Create `examples/extensions/` directory with:
- `CustomCredentialProvider.php`
- `CustomFormatWriter.php`
- `CustomPlatformDecorator.php`
- `CustomEventListener.php`

Each with inline comments explaining the pattern.

**Definition of Done:**
- [ ] 4+ example extensions compile and run
- [ ] Linked from extension author guide

---

### 4.3 Final Review & Checklist (Day 23)

**Checklist from `00-overview.md §6`:**

- [ ] All P0 (Phase 0) components are implemented and tested
- [ ] All P1 (Phase 1) default implementations exist
- [ ] Extension Author Guide is published
- [ ] At least 3 built-in extensions demonstrate the three extension mechanisms
- [ ] All public extension interfaces are tagged `@api`
- [ ] All internal classes are tagged `@internal`
- [ ] Migration guide from Adminer plugins exists
- [ ] Third-party developers can write and register extensions without reading SQLCraft internals
- [ ] `make build` passes (PHPStan, Psalm, Deptrac, cs-fixer, tests)
- [ ] All phase exit criteria met

---

## Risk Mitigation

| Risk | Impact | Mitigation |
|---|---|---|
| `AbstractPlatformDecorator` method enumeration is incomplete | HIGH — breaks decorator pattern | Generate from interface files via script; code review checklist |
| `TenantScopingInterceptor` naive SQL rewriting is unsafe | MED — incorrect SQL rewrites | Mark `@experimental`, document limitations, suggest parser-based alternative |
| Annotation tagging is incomplete or incorrect | MED — API stability contract violated | Automated verification script; manual checklist |
| Integration tests are flaky | LOW — CI red despite correct code | Use deterministic test data; avoid time-based assertions |
| Documentation is incomplete or inaccurate | HIGH — extension authors cannot use the system | Peer review; validate examples compile and run |

---

## Parallelization Opportunities

- **Phase 0.5 (API annotations)** can run in parallel with 0.1–0.4
- **Phase 3 (Documentation)** can run in parallel with Phase 2
- **Phase 1.1 (Format implementations)** is independent from 1.2 (Metadata cache)

**Realistic timeline with 2 developers:**
- Developer A: Phase 0.1–0.4 → Phase 1.1 → Phase 2.1–2.2
- Developer B: Phase 0.5 → Phase 1.2–1.3 → Phase 3 (docs)
- Both: Phase 4 (integration, review)

**Estimated calendar time (2 devs):** 12-15 business days (2.5-3 weeks)

---

## Acceptance Criteria (Definition of Done for Entire Plan)

The extension system implementation is **COMPLETE** and ready for v1.0 when:

1. ✅ All files listed in phases 0–2 exist, compile, and pass tests
2. ✅ All `@api` and `@internal` annotations are in place (per phase 0.5)
3. ✅ Extension Author Guide is published and reviewed
4. ✅ At least 5 built-in extensions ship with SQLCraft
5. ✅ Integration tests verify end-to-end extension registration and execution
6. ✅ `make build` passes without errors or warnings
7. ✅ Third-party extension authors can implement all three mechanisms without reading internal code
8. ✅ Adminer plugin migration guide is complete

---

**Total Estimated Effort:** 18-23 developer-days (4-5 weeks solo, 2.5-3 weeks with 2 developers)
