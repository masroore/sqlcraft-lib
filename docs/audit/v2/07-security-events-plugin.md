# Audit Report 07 — Security, Events, and Plugin System

> **Date:** 2026-07-21
> **Auditor:** automated read-only investigation (Claude Sonnet 5)
> **Scope:** `src/Security/`, `src/Events/`, `src/Contracts/Events/`, `src/Support/SecretRedactor.php`, `src/Execution/QueryExecutor.php`, `src/Query/Paginator.php`, `src/Execution/BatchExecutor.php`
> **Plan docs consulted:** 15-security.md, 16-events.md, 17-plugin-system.md, 07-module-breakdown.md §1/§10, 04-feature-inventory.md §18, 00-overview.md, 18-public-api.md
> **Source of truth:** code beats docs wherever they disagree.

Severity legend: **CRITICAL** = security risk or fundamental missing contract | **HIGH** = significant gap blocking a promised feature | **MEDIUM** = partial implementation, correctness risk, or missing safety default | **LOW** = minor divergence from design, no immediate risk.

---

## 1. Security — `src/Security/`

### 1.1 What Exists

`src/Security/` contains exactly two production classes (plus a `.gitkeep`):

| File | Purpose | Status |
|------|---------|--------|
| `IdentifierQuoter.php` | Wraps `QuotingInterface::quoteIdentifier()` for single and qualified names | Implemented, matches doc §2 |
| `OperatorValidator.php` | Validates WHERE operators against `PlatformInterface::getOperators()` allowlist | Implemented, matches doc §5.1 |

Both are correct and match the design doc. `OperatorValidator::validate()` calls `strtoupper(trim($operator))` before the allowlist check — a small improvement over the doc sketch.

### 1.2 What Is Absent

The Security module owns far more than identifier quoting per docs 04-feature-inventory.md §18 and 07-module-breakdown.md §10. The following are entirely absent from `src/`:

#### 1.2.1 SecurityGuardInterface — CRITICAL

**Promise:** 07-module-breakdown.md §1 (Contracts) lists `SecurityGuardInterface` as a key contract: "Object-level privilege check." 07-module-breakdown.md §10 (Security) states the Security module "Models `Privilege` VOs, user/role structures, and a `SecurityGuard` that evaluates whether a user may perform an action."

**Reality:** No `SecurityGuardInterface` exists anywhere in `src/Contracts/`. No `SecurityGuard` implementation exists. The interface is referenced in the Contracts table but was never created.

**Gap:** The privilege-enforcement pathway promised in the design — where a consumer can ask "can this DB user do X on table Y?" before executing — does not exist.

**Fix hint:** Create `src/Contracts/Security/SecurityGuardInterface.php` with at minimum `canExecute(ConnectionInterface $conn, Privilege $privilege, QualifiedName $object): bool`. Implement a `PrivilegeGuard` that delegates to `PrivilegeInspectorInterface`.

#### 1.2.2 GRANT / REVOKE / User and Role Management — HIGH

**Promise:** 04-feature-inventory.md §18 maps the following to the Security module with `Capability::UserManagement` and `Capability::PrivilegeManagement`:
- List/create/alter/drop users
- Password management (hashing per engine: `caching_sha2_password`, SCRAM-SHA-256, policy-based MSSQL)
- Create/drop roles
- Grant/revoke privileges (object × privilege × grantee matrix)
- Privilege matrix introspection returning `PrivilegeGrant` VO collections

**Reality:** Read-only user introspection exists (`src/Metadata/UserInspector.php`, `src/Contracts/Metadata/UserInspectorInterface.php`, `src/DTO/UserMeta.php`, `src/Collections/UserCollection.php`). Read-only privilege introspection contract exists (`src/Contracts/Metadata/PrivilegeInspectorInterface.php`). That is all.

No write-side exists:
- No `createUser()` / `dropUser()` / `alterUser()`
- No `createRole()` / `dropRole()`
- No `grantPrivilege()` / `revokePrivilege()`
- No `PrivilegeGrant` VO (the doc promises this as the return type of introspection; the actual `PrivilegeCollection` uses the generic `Privilege` VO, not a grant-specific VO)
- No password hashing helpers or engine-specific `ALTER USER ... IDENTIFIED BY` generation

**Quantified gap:** 6 of 6 write operations from §18 are absent. The read side is partially present (user listing exists; privilege matrix introspection is an empty-body interface with no platform-specific implementation visible in the audit).

**Fix hint:** Add a `UserManagerInterface` and `PrivilegeManagerInterface` to `src/Contracts/Security/` with DDL-generating implementations per engine, behind `Capability::UserManagement` and `Capability::PrivilegeManagement` guards respectively.

#### 1.2.3 Credential VO — MEDIUM

**Promise:** 15-security.md §7 defines:

```php
final readonly class Credential {
    public function __construct(
        public readonly string $username,
        #[\SensitiveParameter]
        public readonly string $password,
    ) {}
}
```

**Reality:** No `Credential` class exists anywhere in `src/`. Credentials flow as raw strings inside `ConnectionParameters::$password` (which does carry `#[SensitiveParameter]`). The `Credential` VO was never materialized.

**Fix hint:** Add `src/ValueObjects/Credential.php` and update `ConnectionParameters` to accept it.

#### 1.2.4 Summary — Security Gap Scorecard

| Feature | Doc Reference | Status |
|---------|--------------|--------|
| Identifier quoting | 15 §2 | Implemented |
| Value binding (prepared statements) | 15 §3 | Implemented (in Connection layer) |
| Binary quoting | 15 §4 | Implemented (in Platform layer) |
| Operator allowlisting | 15 §5.1 | Implemented |
| Sort direction enum | 15 §5.2 | Implemented (in Query layer) |
| LIMIT/OFFSET validation | 15 §5.3 | Implemented (in Query/Paginator) |
| Type/aggregate allowlisting | 15 §5.4–5.5 | Implemented (in DDL/Platform layers) |
| SecurityGuardInterface | 07 §1, §10 | **ABSENT** |
| Credential VO with SensitiveParameter | 15 §7 | **ABSENT** |
| GRANT/REVOKE management | 04 §18 | **ABSENT** |
| User/role management | 04 §18 | **ABSENT** (read-only only) |
| Password hashing helpers | 04 §18 | **ABSENT** |

---

## 2. Events — `src/Events/`

### 2.1 Event Class Inventory

The `src/Events/` directory contains 25 concrete event classes plus infrastructure (base classes, dispatchers, provider):

**Infrastructure (non-event) classes in src/Events/:**
- `InterceptionEvent.php` — abstract base for cancellable events
- `ObservabilityEvent.php` — abstract base for fire-and-forget events
- `SQLCraftEventInterface.php` — marker interface
- `SimpleEventDispatcher.php` — PSR-14 implementation
- `SimpleListenerProvider.php` — PSR-14 listener provider
- `ConnectionEventDispatcher.php` — domain event facade for connection lifecycle
- `SchemaEventDispatcher.php` — domain event facade for schema/DDL events
- `ImportExportEventDispatcher.php` — domain event facade for import/export events

**Concrete event classes (25 total):**

| Category | Event | Type | Implemented |
|----------|-------|------|-------------|
| Connection | `ConnectionOpenedEvent` | Observability | Yes |
| Connection | `ConnectionClosedEvent` | Observability | Yes |
| Connection | `ConnectionFailedEvent` | Observability | Yes |
| Connection | `BeforeConnectionOpened` | Interception | Yes |
| Query | `BeforeQueryExecuted` | Interception | Yes |
| Query | `AfterQueryExecuted` | Observability | Yes |
| Query | `QueryFailedEvent` | Observability | Yes |
| Query | `SlowQueryDetectedEvent` | Observability | Yes |
| Query | `BeforeDdlExecuted` | Interception | Yes |
| Query | `AfterDdlExecuted` | Observability | Yes |
| Transaction | `TransactionBeganEvent` | Observability | Yes |
| Transaction | `TransactionCommittedEvent` | Observability | Yes |
| Transaction | `TransactionRolledBackEvent` | Observability | Yes |
| Transaction | `BeforeTransactionBegan` | Interception | Yes |
| Schema | `SchemaChangedEvent` | Observability | Yes |
| Schema | `MetadataFetchedEvent` | Observability | Yes |
| Schema | `BeforeSchemaChange` | Interception | Yes |
| Import/Export | `ImportStartedEvent` | Observability | Yes |
| Import/Export | `ImportProgressEvent` | Observability | Yes |
| Import/Export | `ImportFinishedEvent` | Observability | Yes |
| Import/Export | `ImportFailedEvent` | Observability | Yes |
| Import/Export | `ExportStartedEvent` | Observability | Yes |
| Import/Export | `ExportProgressEvent` | Observability | Yes |
| Import/Export | `ExportFinishedEvent` | Observability | Yes |
| Capability | `CapabilityNotSupportedEvent` | Observability | Yes |

Doc 16 §5 catalogs 25 events. All 25 are present and correctly classified. The audit prompt cites 27; the plan doc itself yields 25 by direct count — no discrepancy in the implementation.

### 2.2 Dispatch Wiring

Event dispatch is split across four dispatch paths, all verified:

| Dispatcher | Dispatches | Wired into |
|-----------|-----------|-----------|
| `QueryExecutor` (direct) | `BeforeQueryExecuted`, `AfterQueryExecuted`, `QueryFailedEvent`, `SlowQueryDetectedEvent`, `BeforeDdlExecuted`, `AfterDdlExecuted` | `src/Execution/QueryExecutor.php` (dispatches via injected `EventDispatcherInterface`) |
| `ConnectionEventDispatcher` | 8 connection/transaction events | `src/Connection/PdoConnectionFactory.php` accepts `ConnectionEventDispatcherInterface` |
| `SchemaEventDispatcher` | `BeforeSchemaChange`, `SchemaChangedEvent`, `MetadataFetchedEvent`, `CapabilityNotSupportedEvent`, `BeforeDdlExecuted`, `AfterDdlExecuted` | `src/DDL/DdlManager.php` accepts `SchemaEventDispatcherInterface` |
| `ImportExportEventDispatcher` | 7 import/export events | `src/Events/ImportExportEventDispatcher.php` (wiring to `Importer`/`Exporter` not confirmed in this audit pass) |

All 25 event classes appear reachable through at least one dispatch path.

### 2.3 `BeforeQueryExecuted::replaceSql()` — Interception Verified

**Promise:** 16-events.md §5.2 specifies that `BeforeQueryExecuted` allows a listener to replace the SQL via `replaceSql(string $sql, array $params): void`.

**Reality:** `src/Events/BeforeQueryExecuted.php` implements `replaceSql()` exactly as specified. `QueryExecutor` reads back the (possibly replaced) SQL and params via `$before->getSql()` / `$before->getParams()` after dispatch and before execution. The interception loop is correct.

**Status:** Fully implemented — no gap.

### 2.4 `SimpleEventDispatcher` / `SimpleListenerProvider` — Verified

Both classes exist and match the design:
- `SimpleEventDispatcher` implements PSR-14 `EventDispatcherInterface`, respects `StoppableEventInterface`
- `SimpleListenerProvider` implements PSR-14 `ListenerProviderInterface`, supports integer priority with stable tie-breaking via a monotonic `$sequence` counter (an improvement over the doc sketch, which only sorts by priority)
- Interface matching via `instanceof` rather than exact class-string lookup — correct for PSR-14

**Status:** Fully implemented — no gap. The sequence tie-breaker is a deliberate improvement.

### 2.5 InterceptionEvent — Verified

`src/Events/InterceptionEvent.php` implements `cancel()`, `isCancelled()`, `stopPropagation()`, `isPropagationStopped()` matching the design. `QueryExecutor::assertNotCancelled()` is called after each `BeforeQueryExecuted` / `BeforeDdlExecuted` dispatch and throws `OperationCancelledException` on cancellation.

**Status:** Fully implemented — no gap.

---

## 3. Plugin System

### 3.1 Design Intent (doc 17)

Doc 17 explicitly rejects a traditional `PluginInterface`/`PluginRegistry`/`__call`-chain model. It defines three extension mechanisms:
1. PSR-14 events (doc 16)
2. DI-swappable service interfaces
3. Explicit extension interfaces (`CredentialProviderInterface`, `FormatWriterInterface`, etc.)

### 3.2 Reality

No `PluginInterface`, `PluginRegistry`, `HookInterface`, or `adminer-plugins/`-style directory scanner exists in `src/`. This is correct and intentional.

The three mechanisms doc 17 specifies are implemented at the primitive level:
- PSR-14 events: fully implemented (see §2 above)
- Interface-first services: all core services are defined as interfaces in `src/Contracts/`
- Explicit extension interfaces: `CredentialProviderInterface`, `FormatWriterInterface`, `ImportSourceInterface`, `DriverInterface`, `PlatformInterface`, etc. all exist in `src/Contracts/`

However, two specific registries that doc 17 declares as stable bootstrap-time APIs are absent:

| Registry | Doc reference | Status |
|----------|--------------|--------|
| `DriverRegistry` | 17 §5, 08 §8 | **Not found in src/** |
| `FormatRegistry` | 17 §5, 14 §7 | **Not found in src/** |

These registries are the "explicit registration" entry points for third-party drivers and export formats. Without them, the primary third-party driver integration path (doc 17 §7) is incomplete.

### 3.3 SQLCraftFactory / DatabaseSession — HIGH

**Promise:** Doc 18 §2 describes `SQLCraftFactory` + `DatabaseSession` as the root consumer entry points, replacing any facade/singleton model. Doc 00 ASCII architecture shows `SQLCraft\Facade / ServiceContainer (opt.)` at the top of the stack.

**Reality:** Neither `SQLCraftFactory` nor `DatabaseSession` exists in `src/`. `DriverRegistry` which `SQLCraftFactory` depends on is also absent.

This is the highest-impact gap in the current implementation: consumers have no documented, ergonomic entry point to wire the library. They must manually assemble `PdoConnectionFactory`, `QueryExecutor`, `SchemaManager`, etc. individually with no guiding top-level class.

**Fix hint:** Implement `src/SQLCraftFactory.php` and `src/DatabaseSession.php` per doc 18 §2-§3. Start with `DriverRegistry` since everything else depends on it.

---

## 4. Facade / ServiceContainer

**Promise:** 00-overview.md ASCII diagram shows `SQLCraft\Facade / ServiceContainer (opt.)` as the consumer-facing entry layer. Doc 18 §2 explicitly rejects a static Facade in favor of the DI-constructed `SQLCraftFactory`.

**Reality:** No `Facade.php`, no `ServiceContainer.php`, and no `SQLCraftFactory.php` exists anywhere in `src/`.

**Severity:** HIGH — the consumer entry point is absent, but this is a usability/ergonomics gap, not a correctness or security issue. The underlying services (`QueryExecutor`, `SchemaManager`, `Paginator`, etc.) work independently.

**Fix hint:** Implement `src/SQLCraftFactory.php` per doc 18 §2.2. The static facade was explicitly rejected per doc 18 §2.1; do not introduce it.

---

## 5. Credential Redaction — `#[SensitiveParameter]` and `SecretRedactor`

### 5.1 Current State

| Mechanism | Location | Coverage |
|-----------|---------|---------|
| `SecretRedactor::dsn()` | `src/Support/SecretRedactor.php` | Redacts password from DSN strings. Wired in `PdoConnectionFactory` only. |
| `#[SensitiveParameter]` | `src/ValueObjects/ConnectionParameters.php` line 23 | Applied to `$password` parameter only. |

### 5.2 Gaps

**Promise:** Doc 15 §7 says `#[\SensitiveParameter]` on "all credential parameters across the codebase." Doc 15 §8 says no parameter values appear in query exceptions and `SecretRedactor` replacement logic is applied to exception messages.

**Reality:**
- `#[SensitiveParameter]` found in exactly one location (`ConnectionParameters::$password`). Absent from any exception constructors, any method that accepts a password string, and from the promised `Credential` VO (which doesn't exist).
- `SecretRedactor` is called in one place (`PdoConnectionFactory`) for DSN string sanitisation in a `ConnectionFailedException` message. It is not wired into the general exception hierarchy.
- Query exception constructors (`SyntaxErrorException`, `QueryExecutionException`, etc.) were not verified to exclude bound parameter values from their messages. Doc 15 §8 promises this explicitly.

**Severity:** MEDIUM — `ConnectionParameters::$password` is protected by `#[SensitiveParameter]` which is the highest-traffic secret. The gap is defence-in-depth: any direct call-site that accepts a password string as a plain `string` parameter loses the protection.

**Fix hint:** Apply `#[SensitiveParameter]` to every method parameter named `$password`, `$credential`, or similar across all constructors and factory methods. Add a `Credential` VO per doc 15 §7 to centralise the annotation.

---

## 6. DoS / Resource-Limit Defaults

### 6.1 Paginator — `$maximumLimit`

**Promise:** Doc 15 §11.1 says `Paginator` has `$maxLimit` (default 10,000 rows per page).

**Reality:** `src/Query/Paginator.php` constructor has `private int $maximumLimit = 10000`. `paginate()` throws `InvalidArgumentException` when `$params->limit > $this->maximumLimit`. Default is enforced.

**Status:** Fully implemented — no gap.

### 6.2 BatchExecutor — `$maximumStatements`

**Promise:** Doc 15 §11.1 says `BatchExecutor` has `$maxStatements` (default 1,000 statements per batch).

**Reality:** `src/Execution/BatchExecutor.php` constructor has `private int $maximumStatements = 1000`. `executeBatch()` throws `InvalidArgumentException` when `count($batch->statements) > $this->maximumStatements`. Default is enforced.

**Status:** Fully implemented — no gap.

### 6.3 Import Statement Cap — MEDIUM

**Promise:** Doc 15 §11.1 says `BatchExecutor` has `$maxStatements` (default 1,000). The Import layer uses `BatchExecutor`.

**Reality:** `src/Import/ImportOptions.php` has `public ?int $maxStatements = null` — nullable, no default cap. The `Importer` checks `$options->maxStatements !== null` before enforcing the cap. A consumer who constructs `ImportOptions` without setting `maxStatements` runs unbounded imports.

**Severity:** MEDIUM — unbounded SQL imports can exhaust memory and connection timeouts. Doc 15 §11.1 intends a safe default, but the import layer leaves it null. `BatchExecutor::$maximumStatements = 1000` only guards programmatic batch calls, not the import pipeline.

**Fix hint:** Change `ImportOptions::$maxStatements` default from `null` to a finite safe value (e.g. `10000`), or document the null explicitly as "unlimited, caller is responsible."

### 6.4 QueryExecutor Timeout — Verified

**Promise:** Doc 15 §11.2 says per-query timeouts delegate to `QueryExecutor::queryWithTimeout()`.

**Reality:** `queryWithTimeout()` is implemented in `src/Execution/QueryExecutor.php`. It validates `$timeoutMs >= 0`, delegates zero-timeout calls to regular `query()`, and wraps non-zero via `$connection->getPlatform()->wrapWithTimeout()`.

**Status:** Fully implemented.

### 6.5 TableSearchService `$rowCap` — ABSENT

**Promise:** Doc 15 §11.1 says `TableSearchService::search()` has `$rowCap` per table (default 1,000).

**Reality:** No `TableSearchService` class was found anywhere in `src/`. The entire cross-table search service is absent.

**Severity:** MEDIUM — the resource cap cannot be evaluated because the service that owns it does not exist. When `TableSearchService` is implemented, the row cap must be wired from the start.

---

## 7. Cross-Cutting Observations

### 7.1 Contracts/Events — Three Facade Interfaces

`src/Contracts/Events/` contains:
- `ConnectionEventDispatcherInterface.php`
- `SchemaEventDispatcherInterface.php`
- `ImportExportEventDispatcherInterface.php`
- `EventDispatcherAwareInterface.php`

These are domain-specific dispatcher facades used as constructor parameters in `PdoConnectionFactory` and `DdlManager`, allowing the event layer to be mocked independently of PSR-14 in unit tests. This is a pragmatic addition not described in doc 16, and it is good design. No gap here.

### 7.2 Test Coverage — Surface Check

| Test file | Events/Security area |
|-----------|---------------------|
| `tests/Unit/Security/SecurityUtilitiesTest.php` | Covers Security utilities (IdentifierQuoter, OperatorValidator) |
| `tests/Unit/Events/ConnectionLifecycleEventsTest.php` | Connection events |
| `tests/Unit/Events/EventTaxonomyTest.php` | Event hierarchy/classification |
| `tests/Unit/Events/ImportExportEventDispatcherTest.php` | Import/export dispatcher |
| `tests/Unit/Events/SchemaEventDispatcherTest.php` | Schema dispatcher |
| `tests/Unit/Events/SimpleEventDispatcherTest.php` | SimpleEventDispatcher + priority |

No test files for the absent Security write-side (GRANT/REVOKE, user management). This is consistent with those features being absent in src/.

---

## 8. Summary Table — All Six Investigation Areas

| Area | Severity | Promise (doc + section) | Reality (path or absence) | Fix hint |
|------|----------|------------------------|--------------------------|----------|
| SecurityGuardInterface | CRITICAL | 07-module-breakdown.md §1, §10 | Absent from `src/Contracts/Security/` | Create interface + PrivilegeGuard implementation |
| GRANT/REVOKE management | HIGH | 04 §18, `Capability::PrivilegeManagement` | No write-side exists; 6/6 write ops absent | Add `UserManagerInterface` + `PrivilegeManagerInterface` + per-engine DDL |
| User/role management DDL | HIGH | 04 §18, `Capability::UserManagement` | Absent; only read inspection present | Add create/alter/drop user + role to Security module |
| SQLCraftFactory / DatabaseSession | HIGH | 18 §2; 00 ASCII diagram | Neither class exists in `src/` | Implement `src/SQLCraftFactory.php` and `src/DatabaseSession.php` per doc 18 |
| DriverRegistry / FormatRegistry | HIGH | 17 §5; 08 §8; 14 §7 | Not found in `src/` | Implement before third-party driver integration |
| Credential VO | MEDIUM | 15 §7 | No `Credential` class in `src/` | Add `src/ValueObjects/Credential.php` |
| `#[SensitiveParameter]` coverage | MEDIUM | 15 §7, §8 ("all credential params") | Only `ConnectionParameters::$password`; not wired across exceptions | Audit all password-accepting signatures; apply annotation consistently |
| Import `maxStatements` default | MEDIUM | 15 §11.1 | `ImportOptions::$maxStatements = null` — unbounded by default | Set a finite default or document as unlimited-by-design |
| TableSearchService `$rowCap` | MEDIUM | 15 §11.1 | `TableSearchService` class absent | Implement service with row cap from the start |
| All 25 event classes | — | 16 §5 | All 25 present and correctly typed | No action needed |
| `BeforeQueryExecuted::replaceSql()` | — | 16 §5.2 | Implemented; QueryExecutor reads it back correctly | No action needed |
| `SimpleEventDispatcher` / `SimpleListenerProvider` | — | 16 §2, §6.2 | Fully implemented; sequence tie-breaker is an improvement | No action needed |
| `InterceptionEvent` cancel/veto loop | — | 16 §4.2, §8 | Fully implemented including `OperationCancelledException` | No action needed |
| Paginator `maximumLimit = 10000` | — | 15 §11.1 | Enforced with `InvalidArgumentException` | No action needed |
| BatchExecutor `maximumStatements = 1000` | — | 15 §11.1 | Enforced with `InvalidArgumentException` | No action needed |
| Plugin system (as doc 17 defines it) | — | 17 §2 | Three-mechanism model implemented at primitive level | No action needed (DriverRegistry/FormatRegistry tracked above) |
| Static Facade rejection | — | 18 §2.1 | Correctly absent; static facade explicitly rejected in doc | No action needed |

---

## 9. Priority Recommendation

1. **Implement `DriverRegistry`** — gates `SQLCraftFactory`, third-party drivers, and the stable extension registration API.
2. **Implement `SQLCraftFactory` + `DatabaseSession`** — unblocks any consumer from using the library end-to-end.
3. **Add `SecurityGuardInterface`** — the contract is already referenced in module-breakdown; the interface itself is a one-day task.
4. **Add `Credential` VO + broaden `#[SensitiveParameter]`** — defence-in-depth against credential leakage in stack traces.
5. **Add `UserManagerInterface` / `PrivilegeManagerInterface`** with at least MySQL and PostgreSQL DDL backends — per §18 of the feature inventory.
6. **Set `ImportOptions::$maxStatements` to a safe non-null default** — single-line change with immediate DoS-protection benefit.
