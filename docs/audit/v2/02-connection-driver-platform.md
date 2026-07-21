# Audit 02 — Connection, Driver, Platform
**Scope:** `src/Connection/`, `src/Driver/`, `src/Platform/`, `src/Contracts/Connection/`, `src/Contracts/Driver/`, `src/Contracts/Platform/`, `tests/Unit/Connection`, `tests/Unit/Driver`, `tests/Unit/Platform`, `tests/Integration/Connection`, `tests/Contract/`
**Plans read in full:** `06-package-architecture.md`, `08-driver-architecture.md`, `10-connection-layer.md`, `07-module-breakdown.md` §§5–7
**Diagram skimmed:** `00-overview.md` ASCII diagram and reading guide
**Status:** READ-ONLY — no source modified
**Date:** 2026-07-21

---

## Summary Table

| # | Severity | Area | One-line |
|---|----------|------|----------|
| 1 | HIGH | Connection | `ConnectionManager` completely absent — multi-connection centerpiece missing |
| 2 | HIGH | Connection | `CredentialProvider` subsystem absent — interface, VO, and all three built-ins missing |
| 3 | HIGH | Connection | Overview ASCII diagram lists `ConnectionPool / LazyConnection / ReadReplicaConnection`; none implemented or even defined as interfaces in `Contracts/Connection/` |
| 4 | MED | Connection | `ConnectionFactory` signature is `@internal` thin wrapper; plan §5.1 signature (DriverRegistry + CredentialProvider + EventDispatcher) never built |
| 5 | MED | Connection | `QueryLogger` interface absent (`07-module-breakdown.md` §5 lists it as a named interface) |
| 6 | MED | Driver | `OracleDriver` / `OraclePlatform` absent — only `.gitkeep` placeholders; `00-overview.md` and `07-module-breakdown.md` list them without any deferral annotation |
| 7 | MED | Driver | No `SQLCraftFactory` / auto-driver-registration bootstrap; plan §8 says "SQLCraftFactory pre-registers the 6 built-in drivers" |
| 8 | LOW | Driver | `AbstractDriver` abstract helper absent; plan §2 and `07-module-breakdown.md` §6 list it as "provided but optional" |
| 9 | LOW | Driver | `DriverRegistry` is instance-based; plan §8 shows static registry (`private static array`, `public static function register/get`) — stale doc, the instance pattern is better but the code and doc describe different APIs |
| 10 | LOW | Deptrac | `deptrac.yaml` `Contracts` ruleset allows `Connection` and `Platform` as deps — grants Contracts permission to import concrete adapters, undermining the hexagonal guarantee |
| 11 | LOW | Docs | `00-overview.md` reading guide rows 06–08 name files that do not exist (`06-connection-layer.md`, `07-driver-platform.md`, `08-capability-model.md`); actual files are `06-package-architecture.md`, `07-module-breakdown.md`, `08-driver-architecture.md`; connection layer is at `10-connection-layer.md` |
| P1 | PASS | Platform | Sub-interface segregation: all five interfaces (`QuotingInterface`, `PaginationInterface`, `TypeMapperInterface`, `DdlDialectInterface`, `IntrospectionDialectInterface`) present and `PlatformInterface` correctly extends all five |
| P2 | PASS | Platform | `MariaDbPlatform` flavor branching: only `getName()`, `getFlavor()`, `getSequencesSql()`, and `buildCapabilityMatrix()` overridden; no `getFlavor() === 'maria'` branches outside capability resolution |
| P3 | PASS | Driver | `SqlServerDriver` and `SqlServerPlatform` wired; `tests/Contract/SqlServer/SqlServerPlatformConformanceTest.php` is fully implemented (not stubbed) |
| P4 | PASS | Infra | Zero `\PDO` usage outside `src/Connection/` and `src/Driver/` — PDO boundary is clean |
| P5 | PASS | Exceptions | `DriverNotFoundException` exists at `src/Exceptions/DriverNotFoundException.php` and is used by `DriverRegistry` |

---

## Detailed Findings

### 1 [HIGH] `ConnectionManager` completely absent

**Promise:** `10-connection-layer.md` §5.2 defines `ConnectionManager` as a named, concrete final class holding named `ConnectionInterface` references — the explicit mechanism for multi-connection support and the architectural counterpart to Adminer's single global `$driver`. `07-module-breakdown.md` §5 lists it in the Public API surface of the Connection module.

**Reality:** No `ConnectionManager.php` exists anywhere under `src/`. No `ConnectionManagerInterface` exists in `src/Contracts/Connection/`. The feature that makes "N concurrent connections to N engines" possible has no implementation and no contract.

**Fix:** Create `src/Contracts/Connection/ConnectionManagerInterface.php` (get/add/closeAll) and `src/Connection/ConnectionManager.php` per plan §5.2; wire it as the composition root's primary connection handle.

---

### 2 [HIGH] `CredentialProvider` subsystem completely absent

**Promise:** `10-connection-layer.md` §4 defines `CredentialProviderInterface` in `SQLCraft\Contracts\Connection`, a `Credential` readonly VO with `#[\SensitiveParameter]`, and three built-in implementations (`ArrayCredentialProvider`, `EnvCredentialProvider`, `CallbackCredentialProvider`). The plan calls this "the explicit boundary between the library and the consumer application" for secret management.

**Reality:** None of these classes exist. `src/Contracts/Connection/` contains no `CredentialProviderInterface`. `src/ValueObjects/` contains no `Credential` class. `src/Connection/` has no provider implementations. The actual `ConnectionFactory` takes a single `DriverInterface` — credentials are not handled at all.

**Fix:** Add `Contracts/Connection/CredentialProviderInterface.php`, `ValueObjects/Credential.php`, and at least `Connection/ArrayCredentialProvider.php` for test use; inject `CredentialProviderInterface` into `ConnectionFactory`.

---

### 3 [HIGH] `ConnectionPool`, `LazyConnection`, `ReadReplicaConnection` absent at every level

**Promise:** `00-overview.md` ASCII diagram (the canonical "what exists" reference) explicitly lists these three classes in the Connection Layer box without any deferral annotation:

```
ConnectionInterface → PdoConnection → \PDO
ConnectionPool / LazyConnection / ReadReplicaConnection
```

`10-connection-layer.md` §13 additionally specifies a `ConnectionPoolInterface` seam with two methods (`acquire`, `release`, `getStats`) and states it should be in `SQLCraft\Contracts\Connection`.

**Reality:** Neither the interfaces nor any implementations exist. `src/Contracts/Connection/` contains only: `ConnectionInterface`, `PdoConnectionFactoryInterface`, `PreparedStatementInterface`, `ResultColumn`, `ResultInterface`. No pool, lazy, or read-replica contracts.

**Note:** `10-connection-layer.md` §13 does mark pool as "v1 does not implement pooling", but the ASCII diagram in `00-overview.md` lists all three with no such qualifier, and the `ConnectionPoolInterface` seam itself (a contract, not an implementation) was committed to for v1.

**Fix:** Add `ConnectionPoolInterface` to `Contracts/Connection/`; annotate `LazyConnection` and `ReadReplicaConnection` in `00-overview.md` as deferred post-v1 to prevent future confusion.

---

### 4 [MED] `ConnectionFactory` signature mismatch

**Promise:** `10-connection-layer.md` §5.1 defines:

```php
final class ConnectionFactory {
    public function __construct(
        private readonly DriverRegistryInterface  $registry,
        private readonly CredentialProviderInterface $credentials,
        private readonly ?EventDispatcherInterface $events = null,
    ) {}
```

**Reality:** `src/Connection/ConnectionFactory.php` (marked `@internal`) takes only a single `DriverInterface`:

```php
final class ConnectionFactory {
    public function __construct(private readonly DriverInterface $driver) {}
    public function connect(ConnectionParameters $parameters): ConnectionInterface {
        return $this->driver->connect($parameters);
    }
}
```

It bypasses the registry, credentials, and events entirely. Additionally a `PdoConnectionFactory` and `PdoConnectionFactoryInterface` exist in the codebase but appear in no plan document.

**Fix:** Reconcile by either (a) expanding `ConnectionFactory` to match the plan or (b) updating the plan to reflect the PdoConnectionFactory approach; document `PdoConnectionFactoryInterface`.

---

### 5 [MED] `QueryLogger` interface absent

**Promise:** `07-module-breakdown.md` §5 explicitly lists in the Connection module's key interfaces/classes table:

> `QueryLogger | Interface | Optional PSR-3-compatible query log hook`

**Reality:** No `QueryLogger` or `QueryLoggerInterface` exists in `src/Contracts/Connection/`, `src/Connection/`, or anywhere in `src/`. The interface is named but never defined.

**Fix:** Add `src/Contracts/Connection/QueryLoggerInterface.php`; inject it as optional into `PdoConnection`.

---

### 6 [MED] `OracleDriver` / `OraclePlatform` absent with no deferral annotation in authoritative docs

**Promise:** `00-overview.md` ASCII diagram lists `OraclePlatform` unconditionally. `07-module-breakdown.md` §6 lists `OracleDriver`; §7 lists `OraclePlatform`. `08-driver-architecture.md` §5 table includes `OraclePlatform | oracle | Double-quote identifiers; rownum subquery; CONNECT BY`.

**Reality:** `src/Driver/Oracle/` and `src/Platform/Oracle/` each contain only a `.gitkeep` file. No driver or platform class exists.

**Note:** The roadmap (`23-roadmap.md`) likely defers Oracle to M8, but neither `00-overview.md`, `07-module-breakdown.md`, nor `08-driver-architecture.md` carry any deferral marker. Readers consulting the overview or module breakdown have no signal that Oracle is not yet built.

**Fix:** Add `> Oracle deferred to M8` annotation to `00-overview.md` ASCII diagram, `07-module-breakdown.md` §§6–7, and `08-driver-architecture.md` §5 table to prevent misleading readers.

---

### 7 [MED] No `SQLCraftFactory` / auto-driver-registration bootstrap

**Promise:** `08-driver-architecture.md` §8: "The `SQLCraftFactory` (or a DI container binding) pre-registers the 6 built-in drivers. Third parties call `DriverRegistry::register(new DuckDbDriver())` in their own ServiceProvider."

**Reality:** No `SQLCraftFactory` class exists anywhere in `src/`. The `DriverRegistry` must be manually constructed and drivers manually registered. There is no single composition-root entry point that wires all built-in drivers, making it impossible to use the library without knowing to register every driver explicitly.

**Fix:** Add `src/SQLCraftFactory.php` (or a bootstrap helper) that instantiates a `DriverRegistry` pre-populated with all four currently-implemented drivers (`MySQLDriver`, `PostgreSQLDriver`, `SqliteDriver`, `SqlServerDriver`).

---

### 8 [LOW] `AbstractDriver` absent (documented as optional helper)

**Promise:** `08-driver-architecture.md` §2: "An `AbstractDriver` helper is provided but optional. Third-party drivers are not forced to extend an SQLCraft class." `07-module-breakdown.md` §6 lists it as "Abstract class | Optional helper base; implements `getName()` boilerplate."

**Reality:** No `AbstractDriver.php` in `src/Driver/`. The four concrete drivers implement `DriverInterface` directly with boilerplate repeated.

**Fix:** Add `src/Driver/AbstractDriver.php` implementing the shared `getName()` / `getPdoDriverNames()` boilerplate; mark it `@internal` or keep it public as documented.

---

### 9 [LOW] `DriverRegistry` static→instance drift

**Promise:** `08-driver-architecture.md` §8 shows `DriverRegistry` with `private static array $drivers` and all-static methods (`public static function register`, `public static function get`, `public static function getRegisteredNames`). `07-module-breakdown.md` §6: "Static factory registry."

**Reality:** `src/Driver/DriverRegistry.php` is instance-based: `private array $drivers`, constructor-injected `iterable<DriverInterface>`, and non-static methods. This is an architecturally better choice (no static global state, DI-friendly), but it means every plan reference to `DriverRegistry::register(...)` (static call syntax) is incorrect, and any code examples copied from the plan will fail.

**Fix:** Update `08-driver-architecture.md` §8 and `07-module-breakdown.md` §6 to reflect the instance-based design. Update all `DriverRegistry::register()` static-call examples in the plan docs to constructor-injection or `$registry->register()` form.

---

### 10 [LOW] Deptrac `Contracts` ruleset too permissive

**Promise:** `06-package-architecture.md` §4 Rule 1: "Contracts → nothing (depends on no other SQLCraft namespace)."

**Reality:** `deptrac.yaml` `Contracts` ruleset lists `Connection` and `Platform` as allowed dependencies:

```yaml
Contracts:
  - ValueObjects
  - DTO
  - Collections
  - Exceptions
  - Capabilities
  - Support
  - Connection      # ← concrete adapter layer
  - Platform        # ← concrete adapter layer
  - Export
  - Import
```

This means if any `src/Contracts/**` file accidentally imports a concrete class from `src/Connection/` or `src/Platform/`, deptrac will silently permit it. The hexagonal guarantee — that Contracts never depend on concrete adapters — is not enforced. A secondary anomaly: `Exceptions` lists `Capabilities` as an allowed dep, while plan Rule 5 says "Exceptions → Contracts, ValueObjects."

**Fix:** Remove `Connection`, `Platform`, `Export`, `Import` from the `Contracts` allowed list in `deptrac.yaml`. Remove `Capabilities` from the `Exceptions` allowed list (or document the deliberate deviation).

---

### 11 [LOW] `00-overview.md` reading guide names non-existent files

**Promise / Reality mismatch:**

| Guide entry | Stated filename | Stated topic | Actual filename | Actual topic |
|-------------|----------------|--------------|-----------------|--------------|
| 06 | `06-connection-layer.md` | ConnectionInterface, PdoConnection, pool, lazy/read-replica | `06-package-architecture.md` | Macro-architecture, layer model |
| 07 | `07-driver-platform.md` | DriverInterface, PlatformInterface, per-engine implementations | `07-module-breakdown.md` | Per-module deep dive (all modules) |
| 08 | `08-capability-model.md` | Capability enum, CapabilityAware trait | `08-driver-architecture.md` | DriverInterface, PlatformInterface, concrete platforms |
| 10 | `10-ddl-service.md` | DDL generation API | `10-connection-layer.md` | ConnectionInterface, pool, lazy/read-replica |

The reading guide was written for a different numbering scheme. A developer opening the overview to navigate to "connection layer" at row 06 will get package architecture instead.

**Fix:** Update the `00-overview.md` reading guide table to match the actual files on disk.

---

## Pass Findings (No Action Required)

**P1 — Platform sub-interface segregation:** `QuotingInterface`, `PaginationInterface`, `TypeMapperInterface`, `DdlDialectInterface`, and `IntrospectionDialectInterface` all exist at `src/Contracts/Platform/`. `PlatformInterface` correctly extends all five. The promise in `08-driver-architecture.md` §3 is fully honoured.

**P2 — `MariaDbPlatform` flavor branching isolated:** Source at `src/Platform/MariaDbPlatform.php` contains only four overrides: `getName()`, `getFlavor()`, `getSequencesSql()`, and `buildCapabilityMatrix()`. `src/Platform/MySQLPlatform.php` has a single `getFlavor()` occurrence (the method declaration). No `getFlavor() === 'maria'` branches appear anywhere outside capability resolution. The architectural constraint in `08-driver-architecture.md` §6 is upheld.

**P3 — `SqlServerDriver` / `SqlServerPlatform` wired and tested:** Both concrete classes exist. `tests/Contract/SqlServer/SqlServerPlatformConformanceTest.php` is fully implemented (not empty or stubbed) and wires the driver against a live MSSQL environment via env vars.

**P4 — PDO boundary clean:** `grep -rn '\PDO'` across all `src/` excluding `src/Connection/` and `src/Driver/` returns no results. PDO never leaks past the adapter layer.

**P5 — `DriverNotFoundException` present:** `src/Exceptions/DriverNotFoundException.php` exists and is correctly used by `src/Driver/DriverRegistry.php`.
