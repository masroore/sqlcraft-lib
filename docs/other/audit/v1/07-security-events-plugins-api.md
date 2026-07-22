# 07 — Security, Events, Plugin System & Public API Audit

> **Status:** Final
> **Audit date:** 2026-07-21
> **Baseline:** `master @ 6d50506`
> **Plans reviewed:** `15-security.md`, `16-events.md`, `17-plugin-system.md`, `18-public-api.md`
> **Implementation reviewed:** `src/Security/`, `src/Events/`, `src/Contracts/Security/`, `src/Contracts/Events/`, `src/Driver/DriverRegistry.php`, `src/Schema/SchemaManagerFactory.php`, `src/` root (search for facade/factory classes), `examples/`

> **Scope note:** Plan 18 §7 contains an explicit self-caveat: `SQLCraftFactory`/`DatabaseSession` are "planned composition-root API, not claimed as an implemented class in this release," and "`SecurityGuardInterface` is also deferred." Several gaps below are therefore *acknowledged deferrals* in the plan itself, flagged distinctly.

---

## 1. Gaps

- **MODERATE (acknowledged deferral) — Public API / facade: no entry point exists.** `src/*.php` root returns nothing — there is no `SQLCraftFactory`, `DatabaseSession`, `ServiceContainer`, or `Facade` class anywhere in code (the only matches for those names are in `docs/plans/18-public-api.md`). The `QWEN.md` overview diagram's "SQLCraft Facade / ServiceContainer" is unrealized. Plan 18 §2 designs `SQLCraftFactory::connect()/fromConnection()` and `DatabaseSession::{schema,ddl,query,export,import,security,capabilities,transaction,connection,platformName}()`; none exist. Plan 18 §7 explicitly defers this and says the release instead exposes the graph via `DriverRegistry`, drivers, `SchemaManagerFactory` (present) and service constructors. Planned-and-missing, but the plan owns the deferral.

- **MODERATE (acknowledged deferral) — `SecurityGuardInterface` / `security()` service absent.** `src/Contracts/Security/` contains only `.gitkeep`. Plan 18 §2.2 (`DatabaseSession::security(): SecurityGuardInterface`) and §6 (`InsufficientPrivilegesException` thrown when "SecurityGuard denies an action") reference it; §7 defers it. The exception `src/Exceptions/InsufficientPrivilegesException.php` exists, but no guard produces it. The shipped security surface is just the two construction-time validators (below).

- **MODERATE (genuine gap) — `CredentialProviderInterface` + `Credential` VO absent.** Plan 15 §7 and plan 17 §2.3 (mechanism-3 extension interface, "10-connection-layer.md §4") specify a `CredentialProviderInterface` and a `Credential` VO with `#[\SensitiveParameter]`. Neither exists (zero grep matches). Credentials are carried directly on `src/ValueObjects/ConnectionParameters.php` (password field with `#[SensitiveParameter]`). The planned credential-abstraction seam is missing. (Cross-ref: [03](03-connection-layer.md).)

- **MODERATE — `FormatRegistry` + `SQLCraft\Extension` namespace absent.** Plan 17's header declares namespace root `SQLCraft\Extension` (registry helpers), and §2.3/§3/§5 repeatedly reference `FormatRegistry::registerWriter(...)` as the typed replacement for Adminer's `dumpFormat` append-hook. No `SQLCraft\Extension` namespace and no `FormatRegistry` class exist. The underlying format seams (`FormatWriterInterface`, `SinkInterface`, `ImportSourceInterface`) do exist, but the named *registry* mechanism for registering extra formats/drivers does not. (Cross-ref: [06](06-import-export.md).)

- **MINOR — `FormatReaderInterface` and `DriverRegistryInterface` absent.** Plan 17 §2.3 lists both as stable public extension interfaces. `src/Contracts/Import/` has `ImportSourceInterface`/`ImporterInterface`/`CsvImporterInterface` but no `FormatReaderInterface`; `src/Contracts/Driver/` has only `DriverInterface` (a concrete `DriverRegistry` exists, but no interface — see Drift).

- **MINOR — `TableSearchService` row cap absent.** Plan 15 §11.1 specifies `TableSearchService::search()` with `$rowCap` default 1,000. No `TableSearchService` class exists in `src/` (the service itself is unbuilt — cross-ref [04](04-schema-ddl.md)), so this DoS cap is missing.

## 2. Drift

- **Plugin system: NOT a headline gap — substantially implemented via the plan's three mechanisms.** Verified carefully: plan 17 §9.5 *explicitly rejects* a monolithic `SQLCraft\Plugin` base class and §9.1 rejects directory scanning, so the absence of any "Plugin" class is **by design**, not a gap (the only `Plugin` string matches in `src/` are the MySQL `auth_plugin` column — `DTO/UserMeta.php`, `Platform/MySQLPlatform.php:490`). Plan 17 defines the extension system as three mechanisms, and they are largely present:
  - **Mechanism 1 (PSR-14 events):** fully implemented and wired (see below).
  - **Mechanism 2 (DI interface swaps):** services are interface-first (`Contracts/*`), enabling substitution.
  - **Mechanism 3 (explicit extension interfaces):** mostly present — `MetadataCacheInterface` ✓, `QueryHistoryInterface` ✓, `FormatWriterInterface` ✓, `SinkInterface` ✓, `ImportSourceInterface` ✓, `DriverInterface` ✓, `TransactionManagerInterface` ✓, PSR-14 `EventDispatcherInterface` ✓; missing `CredentialProviderInterface`, `FormatReaderInterface`, `DriverRegistryInterface` (above).

  The accurate finding is "specific named seams absent," not "plugin system unimplemented."

- **MINOR — Credential redaction implemented differently than planned.** Plan 15 §7/§8 describe a `Credential` VO plus a "custom exception formatter" replacing password fields with `[REDACTED]`. Implementation instead uses `src/Support/SecretRedactor.php` (regex-redacts DSN user/password to `[redacted]`, used in `src/Connection/PdoConnectionFactory.php:64`) plus `#[SensitiveParameter]` on `ConnectionParameters`. The intent (no secrets in exceptions) is met for DSNs, but there is no separate `Credential` VO and no general context/exception formatter.

- **MINOR–MODERATE — Resource-limit defaults weakened vs plan.** Plan 15 §11.1 promises a `BatchExecutor` with `$maxStatements` default 1,000. The query-side `BatchExecutor` does have this cap (`src/Execution/BatchExecutor.php`), but the **import path** uses `Import/ImportOptions::$maxStatements` which **defaults to `null` (unlimited)** (`src/Import/Importer.php:166`). The conservative default the plan promises is not applied to imports. Conversely, pagination caps *do* match: `Query/Paginator.php` `$maximumLimit = 10000` and `Query/PaginationParams.php` validates `page >= 1`, `limit >= 1` (plan 15 §5.3/§11.1 ✓); `queryWithTimeout()` exists in `Execution/QueryExecutor.php` (§11.2 ✓, though inert — see [05](05-query-engine.md)).

- **MINOR — `DriverRegistry` does not pre-register built-in drivers.** Plan 18 §3.1/§9 state "DriverRegistry ships pre-registered with the 6 built-in drivers; no manual `register()` call needed." The actual `src/Driver/DriverRegistry.php` constructor takes `iterable $drivers = []` and registers nothing by default — the consumer must inject built-ins. (Consistent with the absent `SQLCraftFactory` that was supposed to do this. Cross-ref: [02](02-driver-platform-capabilities.md).)

- **MINOR — SQL guardrails: one small drift.** Confirmed implemented: `Security/IdentifierQuoter.php` (plan 15 §2), `Security/OperatorValidator.php` (§5.1 — adds `strtoupper(trim())` normalization not in the plan), aggregate-function allowlisting via `SelectQueryRenderer::getSupportedAggregateFunctions()` + `ColumnSelection` regex (§5.5 ✓), and `getSupportedTypes()` infrastructure on platforms/`TypeMapperInterface` (§5.4 — allowlist exists; not confirmed that `DdlBuilder` enforces it before rendering).

## 3. Events — Faithful (the standout success)

All ~26 planned events exist in `src/Events/` and match plan 16 §5's taxonomy:

- **Connection:** `ConnectionOpened/Closed/Failed`, `BeforeConnectionOpened`
- **Query:** `BeforeQueryExecuted`, `AfterQueryExecuted`, `QueryFailedEvent`, `SlowQueryDetectedEvent`, `BeforeDdlExecuted`, `AfterDdlExecuted`
- **Transaction:** `TransactionBegan/Committed/RolledBack`, `BeforeTransactionBegan`
- **Schema/Metadata:** `SchemaChangedEvent`, `MetadataFetchedEvent`, `BeforeSchemaChange`
- **Import/Export:** all seven
- **Capability:** `CapabilityNotSupportedEvent`

PSR-14 compliance confirmed: `SimpleEventDispatcher implements EventDispatcherInterface`; `InterceptionEvent implements StoppableEventInterface` with `cancel()/isCancelled()/cancelReason`; `BeforeQueryExecuted::replaceSql()` per §5.2; `EventDispatcherAwareInterface` per §2. The veto path is real: `Execution/QueryExecutor.php` emits `BeforeQueryExecuted`/`BeforeDdlExecuted`, calls `assertNotCancelled()`, and throws `OperationCancelledException`; `ConnectionEventDispatcher`/`SchemaEventDispatcher` do likewise for connection/transaction/schema. Slow-query threshold (`QueryExecutor::$slowQueryThresholdMs`) matches §5.7.

**Drift (MINOR):** events are emitted through three dedicated helper services (`ConnectionEventDispatcher`, `SchemaEventDispatcher`, `ImportExportEventDispatcher`) rather than inline in each service as plan 16 §5's illustrative `QueryExecutor` snippet shows — functionally equivalent.

## 4. Extras

- **Typed event-dispatcher helper trio** — `Events/ConnectionEventDispatcher.php`, `SchemaEventDispatcher.php`, `ImportExportEventDispatcher.php` plus their contracts in `Contracts/Events/`. Not named in plan 16; a clean architectural addition wrapping the PSR-14 dispatcher.
- **`Support/SecretRedactor.php`** — concrete DSN secret-redaction utility (plan 15 speaks only of an exception-formatter policy, not a named class).
- **User-listing introspection** — `Metadata/UserInspector.php`, `DTO/UserMeta.php`, `MetadataFactoryInterface::createUserMeta()`, `ValueObjects/Privilege.php` + `Collections/PrivilegeCollection.php`, `Contracts/Metadata/PrivilegeInspectorInterface.php`. Plan 15 §10 plans only privilege *introspection*; user listing is an extra. **Note:** user/privilege *management* (CREATE USER / GRANT) is neither planned in 15 nor implemented — so the `QWEN.md` label "`Security/` = User/privilege management" is inaccurate vs both plan and code; `src/Security/` actually holds only `IdentifierQuoter` + `OperatorValidator`.
- **Multiple `QueryHistoryInterface` implementations** — `InMemoryQueryHistory`, `NullQueryHistory`, `CallbackQueryHistory` (plan 17 names only the interface); `Schema/NullMetadataCache.php`.
- **`ExportSourceInterface`, `CsvImporterInterface`, `CsvImportOptions`** — additional import/export seams beyond the plan's named interfaces.

## 5. Summary

The event system is the standout success: the full plan-16 catalog exists, is PSR-14 compliant, and is genuinely emitted with a working cancellation/veto path. The "plugin system" is **not** a headline gap — plan 17 deliberately rejects a monolithic Plugin class, and its three real mechanisms are substantially implemented; only specific named seams are missing (`CredentialProviderInterface`, `FormatReaderInterface`, `DriverRegistryInterface`, `FormatRegistry`, and the `SQLCraft\Extension` namespace). The largest genuine gaps are the public-API entry point (`SQLCraftFactory`/`DatabaseSession`) and `SecurityGuardInterface`, both of which plan 18 §7 explicitly defers; secondary drifts are the unlimited-by-default import statement cap, the non-pre-registering `DriverRegistry`, and credential handling via `SecretRedactor`/`ConnectionParameters` instead of the planned `Credential` VO + `CredentialProviderInterface`.
