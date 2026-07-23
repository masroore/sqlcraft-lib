# Extension System Implementation Audit

> **Audit date:** 2026-07-23
> **Scope:** `docs/other/plans/extensions/`, `docs/other/plans/extensions-revised/`, current implementation, tests, examples, and active documentation
> **Verdict:** Partial implementation; not release-complete
> **Normative baseline:** Revised ADR, implementation handoff, and verification plan

## 1. Executive Summary

The extension architecture is structurally present. Foundational contracts, platform roles, builder composition, driver definitions, metadata inspector sets, query interception, credential chaining, format factories, and process-manager classes have been implemented.

The revised plan has not been implemented completely. Material gaps remain in connection lifecycle behavior, event wiring, format lifetime guarantees, Adminer drift detection, third-party conformance coverage, stable API enforcement, process-control verification, and repository-wide documentation migration.

The original files in `docs/other/plans/extensions/` are historical. The revised handoff explicitly gives them lowest precedence and says not to restore rejected abstractions merely because an older plan names them:

- `docs/other/plans/extensions-revised/04-implementation-handoff.md:18-26`

Accordingly, omitted original proposals such as service providers, bundles, broad platform decorators, schema visibility filters, and regex SQL security are intentional design changes rather than incomplete implementation.

### Overall work-package result

| Work package | Result |
|---|---|
| WP-0 — Baseline truth and dependencies | Partial |
| WP-1 — Foundational contracts, identifiers, exceptions | Mostly complete |
| WP-2 — Platform role aggregate | Mostly complete |
| WP-3 — Metadata inspector set and driver registry | Partial |
| WP-4 — Query interceptor pipeline | Partial |
| WP-5 — Credentials and connection initialization | Partial |
| WP-6 — Builder, events, immutable snapshot | Partial |
| WP-7 — Format factories and reader liveness | Partial |
| WP-8 — Schema surface and process managers | Partial |
| WP-9 — Third-party conformance | Partial |
| WP-10 — Stable surface, documentation, release gates | Partial |

## 2. Plan Precedence

When documents conflict, apply this order:

1. `extensions-revised/00-plugin-system-adr.md` — architectural boundaries.
2. `extensions-revised/04-implementation-handoff.md` — concrete choices and sequencing.
3. `extensions-revised/03-verification.md` — observable acceptance behavior.
4. `extensions-revised/01-extension-system-plan.md` — supporting design explanation.
5. `extensions-revised/02-adminer-5.5.0-hook-matrix.md` — hook inventory and dispositions.
6. Files in `extensions/` — historical reference only.

The revised architecture targets Adminer capability parity, not Adminer inheritance, method names, plugin objects, or dispatch compatibility.

## 3. High-Severity Implementation Drift

### 3.1 Connection failures are misclassified as initialization failures

The handoff says to wrap only initializer and registration failures after a connection exists:

- `docs/other/plans/extensions-revised/04-implementation-handoff.md:757-789`

The current factory catch block includes:

- driver connection;
- platform identity validation;
- initializers;
- connection-manager registration;
- `ConnectionOpenedEvent` dispatch.

Evidence:

- `src/SQLCraftFactory.php:114-140`

A connection failure is therefore dispatched as `ConnectionInitializationFailedEvent` and wrapped in `ConnectionInitializationException`. The original connection-failure classification is no longer the externally visible exception type.

**Required correction:** Narrow the initialization catch boundary. Preserve connection failures and normal event-listener exceptions according to their original taxonomy.

### 3.2 A throwing opened-event listener leaves a closed connection registered

The factory adds the connection to `ConnectionManager` and then dispatches the opened event inside the initialization catch block:

- registration: `src/SQLCraftFactory.php:124`
- opened event: `src/SQLCraftFactory.php:125`
- catch and close: `src/SQLCraftFactory.php:126-139`

The catch closes the connection but does not remove it from `ConnectionManager`. A retry using the same connection label then encounters the retained entry.

Runtime reproduction produced:

```text
SQLCraft\Exceptions\ConnectionInitializationException
previous: RuntimeException
connection retained in manager: yes
```

This also conflicts with the verification requirement that normal event-listener exceptions propagate:

- `docs/other/plans/extensions-revised/03-verification.md:326-338`

**Required correction:** Dispatch the opened event outside the initializer-only catch, or add explicit manager rollback while preserving normal listener exception semantics.

### 3.3 Import/export events are not wired into builder-created sessions

The handoff requires one effective event dispatcher to reach all event adapter wrappers:

- `docs/other/plans/extensions-revised/04-implementation-handoff.md:907-918`

The factory creates these services without an `ImportExportEventDispatcher`:

- `Exporter`: `src/SQLCraftFactory.php:164`
- `CsvImporter`: `src/SQLCraftFactory.php:165`
- `Importer`: `src/SQLCraftFactory.php:166`

All three support an event adapter:

- `src/Export/Exporter.php:25-51`
- `src/Import/CsvImporter.php:21-27`
- `src/Import/Importer.php:17-25`

Consequently, SQLCraft-owned listeners and external dispatchers configured through the builder do not observe factory-session import/export lifecycle events.

**Required correction:** Construct one `ImportExportEventDispatcher` from the effective dispatcher and pass it to exporter, importer, and CSV importer.

### 3.4 Adminer drift test does not compare against Adminer source

Required behavior:

- extract Adminer public methods;
- compare the exact ordered set with the matrix;
- verify the exact five append hooks.

Plan evidence:

- `docs/other/plans/extensions-revised/04-implementation-handoff.md:271-292`
- `docs/other/plans/extensions-revised/03-verification.md:27-48`

Current test only parses the matrix document and verifies its own count:

- `tests/Unit/Architecture/AdminerExtensionMatrixTest.php:11-34`

A manual comparison against `../adminer/adminer/include/adminer.inc.php` and `plugins.inc.php` found the current matrix accurate:

```text
Adminer source methods: 79 unique
Matrix methods:         79 unique
Exact order:            match
Missing/stale names:    none
Append hooks:           exact five
```

The current data is correct, but CI cannot detect future upstream drift.

**Required correction:** Make the test locate or receive the Adminer source and assert exact source-to-matrix equality and the exact append set.

### 3.5 Active documentation still uses the removed factory constructor

The revised plan requires existing examples and tests to migrate to the builder:

- `docs/other/plans/extensions-revised/00-plugin-system-adr.md:156-161`
- `docs/other/plans/extensions-revised/04-implementation-handoff.md:856-865`

`SQLCraftFactory` now has eleven required constructor arguments and is marked `@internal`:

- `src/SQLCraftFactory.php:46-70`

Active documentation still contains incompatible examples, including:

- `docs/getting-started/quick-start.md:16-24`
- `docs/user-guide/events.md:9-16`
- `docs/getting-started/basic-concepts.md`
- `docs/getting-started/installation.md`
- `docs/user-guide/connections.md`
- `docs/advanced/framework-integration.md`
- `docs/advanced/streaming.md`
- `docs/user-guide/schema-introspection.md`

Typical stale usage:

```php
$factory = new SQLCraftFactory();
```

**Required correction:** Migrate all active examples to `SQLCraftBuilder::defaults()` or an explicitly configured `new SQLCraftBuilder()` followed by `build()`.

## 4. Medium-Severity Drift and Incomplete Acceptance

### 4.1 Format registry still permits shared mutable adapters

The revised format contract requires factories and fresh adapter instances per resolution:

- `docs/other/plans/extensions-revised/04-implementation-handoff.md:947-997`

Legacy public methods retain the same supplied instance:

- writer: `src/Export/FormatRegistry.php:79-86`
- reader: `src/Export/FormatRegistry.php:108-115`

The builder path uses factories correctly, but consumers can still introduce state leakage through the exposed registry. `DatabaseSession::formats()` also exposes this mutable registry, creating an indirect late-mutation path after factory construction.

**Required decision:** Remove/internalize legacy instance registration, or explicitly narrow the freshness guarantee to builder registrations and document the compatibility exception.

### 4.2 Stable API manifest is not a compatibility baseline

The revised plan requires exact public signatures, transitive types, enum cases, and internal-type checks:

- `docs/other/plans/extensions-revised/03-verification.md:371-385`
- `docs/other/plans/extensions-revised/04-implementation-handoff.md:1138-1155`

Current test checks only that listed methods exist and remain public:

- `tests/Unit/Architecture/StableApiTest.php:12-25`

It does not verify:

- parameter types or ordering;
- return types;
- exact public method sets;
- enum cases;
- newly exposed public methods;
- all transitive signature types;
- compatibility with a committed previous snapshot.

There is also a policy contradiction:

- `SQLCraftFactory` is `@internal`: `src/SQLCraftFactory.php:46`
- `SQLCraftFactory` is in the stable manifest: `tests/Fixtures/stable-api.php:49`

**Required correction:** Store normalized reflection metadata for the intended stable surface and compare exact compatibility properties. Reconcile whether the factory class is internal or stable while keeping its constructor internal to the builder path.

### 4.3 Third-party conformance proves only part of WP-9

The handoff requires twelve observable behaviors:

- `docs/other/plans/extensions-revised/04-implementation-handoff.md:1087-1106`

Current test proves driver registration, aliasing, one metadata creation, query transformation, history, writer resolution, and basic initializer/opened behavior:

- `tests/Contract/Extension/ThirdPartyDriverConformanceTest.php:34-80`

It does not prove:

- metadata decoration reaches schema and export;
- custom reader resolution;
- CSV import behavior;
- exact initializer-before-open ordering;
- typed unsupported-process behavior through `session->processes()`;
- absence of `fixturedb` from production source;
- complete role replacement behavior.

### 4.4 Builder snapshot and process-control tests are missing

The handoff explicitly requests:

- `tests/Unit/SQLCraftBuilderTest.php`: `04-implementation-handoff.md:824-826`
- builder mutation/snapshot test: `04-implementation-handoff.md:920-924`
- process-manager validation and liveness tests: `04-implementation-handoff.md:1053-1069`

No dedicated committed builder snapshot test exists. No process acceptance tests cover:

- zero, negative, decimal, signed, whitespace, or SQL-fragment IDs;
- numeric-string normalization;
- `QueryKind::Administrative`;
- interception, history, and event flow;
- wrong process-factory output;
- the relationship between advertised `Kill` capability and a reachable manager.

The current architecture liveness test verifies only that capability names belong to the enum:

- `tests/Unit/Architecture/SeamLivenessTest.php:71-89`

### 4.5 Event-mode conflicts fail at the wrong boundary

Verification says configuring both listener modes fails at `build()`:

- `docs/other/plans/extensions-revised/03-verification.md:349-352`
- `docs/other/plans/extensions-revised/04-implementation-handoff.md:893-905`

Implementation throws immediately from `listen()` or `eventDispatcher()`:

- `src/SQLCraftBuilder.php:256-275`

The invalid combination is rejected, but this differs from the promised collect-then-validate builder lifecycle.

## 5. Work-Package Assessment

### WP-0 — Baseline Truth and Dependencies: Partial

Implemented:

- `psr/event-dispatcher` is a runtime dependency.
- Changelog no longer claims a nonexistent release tag.
- Matrix contains the current 79 hooks and correct append set.

Missing:

- source-derived automated drift comparison.

### WP-1 — Foundational Contracts, Identifiers, and Exceptions: Mostly Complete

Implemented:

- string driver identifiers;
- extension identifier normalization;
- initializer, interceptor, process-manager, and metadata-set contracts;
- `QueryRequest` and `QueryKind`;
- typed registration/configuration exceptions.

Remaining concern:

- acceptance tests do not cover every prescribed invalid identifier, alias, and factory-output case.

### WP-2 — Platform Role Aggregate: Mostly Complete

Implemented:

- twelve-method `PlatformInterface`;
- five role interfaces and accessors;
- `PlatformRoles` with immutable withers;
- `ComposedPlatform`;
- built-in platforms returning themselves as roles.

Missing or weak:

- exact method-count assertion;
- focused one-role replacement test;
- unsupported-role typed-failure test.

### WP-3 — Metadata Inspector Set and Driver Registry: Partial

Implemented:

- per-connection `MetadataInspectorSet`;
- driver definitions containing metadata and optional process factories;
- shared decorated set used by schema, export, CSV import, process listing, and privilege security;
- removal of built-in platform switch from `SchemaManagerFactory`.

Missing or weak:

- decorator ordering and cross-service identity tests;
- complete alias-chain and replacement tests;
- third-party metadata decoration conformance.

### WP-4 — Query Interceptor Pipeline: Partial

Implemented:

- ordered interceptor pipeline;
- query, execute, DDL, timeout, import, batch, typed builder, export, and process executor paths are substantially wired;
- query events no longer mutate SQL;
- final SQL reaches history and failure/success events.

Missing or weak:

- complete path matrix from verification section 8;
- explicit timeout-once tests;
- invalid provenance and mixed-key test coverage;
- comprehensive event-order tests.

### WP-5 — Credential Chain and Connection Initialization: Partial

Implemented:

- nullable credential misses;
- first-non-null chain behavior;
- provider exceptions propagate;
- ordered initializers;
- opened event after initializers and manager registration.

Incorrect or incomplete:

- overly broad lifecycle catch;
- connection/opened-listener taxonomy drift;
- retained closed connection after opened-listener failure;
- incomplete failure and secret-safety acceptance coverage.

### WP-6 — Builder, Event Modes, and Factory Snapshot: Partial

Implemented:

- mutable builder and immutable factory configuration arrays;
- defaults versus empty-builder distinction;
- driver, alias, format, credentials, history, cache, initializer, interceptor, metadata decorator, and event configuration;
- core-first composite dispatcher.

Missing or incorrect:

- import/export event adapter wiring;
- dedicated snapshot test;
- conflict validation occurs before `build()` rather than at it;
- old constructor remains widely documented.

### WP-7 — Format Factories and Reader Liveness: Partial

Implemented:

- builder stores writer and reader factories;
- session exposes formats;
- factory output validates type and canonical format name;
- builder registrations create fresh objects.

Remaining drift:

- legacy instance registration returns shared mutable adapters;
- session registry remains mutable;
- distinct-instance acceptance tests are incomplete.

### WP-8 — Schema Surface and Process Managers: Partial

Implemented:

- `DatabaseSession::schema()` returns concrete `SchemaManager`;
- process managers and factories for MySQL, PostgreSQL, and SQL Server;
- SQLite does not advertise `Kill`;
- kill uses administrative executor path.

Missing:

- required process ID validation tests;
- capability-to-manager liveness proof;
- full interception/history/event tests for kill;
- server-version context in the session unsupported-capability exception.

### WP-9 — Third-Party Driver and Cross-Seam Conformance: Partial

Implemented:

- non-enum driver identifier;
- public builder registration and alias;
- custom platform roles;
- metadata factory;
- query interceptor and history;
- custom writer;
- initializer and opened listener;
- basic export path.

Missing:

- full twelve-point conformance proof listed above.

### WP-10 — Stable Surface, Documentation, and Release Gates: Partial

Implemented:

- extension author guide;
- Adminer migration guide;
- initial stable manifest;
- architecture tests;
- runtime PSR-14 dependency.

Missing or inconsistent:

- repository-wide documentation migration;
- exact stable signature enforcement;
- compiled/smoke-tested example set for every required seam;
- complete release-gate acceptance coverage;
- active plan status updates.

## 6. Original-to-Revised Plan Crosswalk

The following original proposals were deliberately superseded and should not be treated as missing implementation:

| Original proposal | Revised disposition |
|---|---|
| `ServiceProviderInterface` and `ExtensionBundle` | Rejected; builder is sole composition root |
| `ListenableProviderInterface` | Replaced by owned listeners or external dispatcher mode |
| Broad platform/driver decorators | Replaced by five small platform roles |
| `BeforeQueryExecuted::replaceSql()` | Replaced by ordered query interceptors |
| Credential fallback after provider exception | Rejected; only `null` falls through |
| Schema/database visibility filters | Rejected as an authorization mechanism |
| Regex read-only enforcement | Explicitly prohibited |
| Regex tenant SQL rewriting | Explicitly prohibited |
| Shared writer/reader instances | Replaced by factories and fresh instances |
| Blanket `@api` annotations | Replaced by a tested stable allowlist |
| Built-in extension-count target | Removed; completion is based on liveness and conformance |

Important semantic reversals:

| Original behavior | Revised behavior |
|---|---|
| SQL mutation through PSR-14 event | Ordered typed interceptor; event remains read-only |
| Provider exceptions trigger credential fallback | Provider exceptions propagate immediately |
| Instance registry considered complete | Factory registry with fresh adapter lifetime |
| Platform-wide forwarding decorators | Five composable role interfaces |
| `afterConnect` implemented as opened-event listener | Initializer runs before opened event |
| Visibility filtering described as security | Consumer presentation behavior only |
| Multiple registration abstractions | Builder-only composition root |

## 7. Plan and Documentation Inconsistencies

1. `extensions-revised/04-implementation-handoff.md:3` still says no implementation has been performed.
2. Historical files say “not yet implemented” despite completed implementation commits.
3. `04-implementation-handoff.md:268` references `extensions/revised/` instead of `extensions-revised/`.
4. The parity matrix calls itself normative, but the explicit conflict-precedence list omits it.
5. The ADR describes platform roles as part of the atomic engine definition, while `DriverDefinition` contains no explicit role field; roles are supplied indirectly through the driver factory.
6. `SQLCraftFactory` is both `@internal` and included in the stable API manifest.
7. The handoff says failure history uses final SQL and parameters, but `QueryHistoryEntry` has no parameter field:
   - plan: `04-implementation-handoff.md:696-697`
   - implementation: `src/Contracts/Execution/QueryHistoryEntry.php:7-17`
8. Format lifetime wording varies between “per session or operation” and “fresh on every resolution.”
9. The exact stable portion of concrete `SchemaManager` remains unspecified despite returning it from the stable session surface.

## 8. Verification Results

Commands and checks completed during the audit:

| Check | Result |
|---|---|
| Unit suite | 400 tests; 2,233 assertions; 1 skipped; 1 deprecation |
| Contract suite | 26 tests; 19 assertions; 20 skipped |
| Golden suite | 5 tests; 10 assertions |
| Integration suite | 23 tests; 38 assertions; 9 skipped |
| Composer validation | Passed |
| PHPStan | Passed |
| Psalm | Passed |
| Deptrac | Passed |
| Rector dry-run gate | Passed during audit |
| Manual Adminer source/matrix comparison | Exact 79-name ordered match; exact append set |
| Opened-listener lifecycle reproduction | Failed as described; closed connection retained |

External-engine skips mean MySQL, MariaDB, PostgreSQL, and SQL Server behavior was not fully executed in the local environment. Skipped tests are environment limitations, not passing evidence.

## 9. Recommended Correction Order

1. Fix connection lifecycle catch boundaries, exception taxonomy, and manager cleanup.
2. Wire `ImportExportEventDispatcher` into exporter, importer, and CSV importer.
3. Implement the source-derived Adminer matrix drift test.
4. Migrate every active example and guide to `SQLCraftBuilder`.
5. Add builder snapshot and event-mode composition tests.
6. Complete WP-9 third-party conformance coverage.
7. Add process-manager validation and administrative execution tests.
8. Remove/internalize legacy mutable format registration or document a deliberate compatibility exception.
9. Replace the stable API existence test with exact signature/snapshot compatibility checks.
10. Reconcile plan statuses, path typo, factory stability policy, history parameter wording, and format lifetime language.

## 10. Final Assessment

The extension system is usable on supported happy paths, and the revised architecture is recognizable in production code. It is not complete under its own acceptance definition.

Release completion requires closing the runtime event/lifecycle defects, proving every builder seam through factory-created sessions, finishing third-party and process-control conformance, enforcing an actual stable API baseline, and migrating active documentation away from the removed constructor path.
