# 02 — Driver, Platform & Capability Model Audit

> **Status:** Final
> **Audit date:** 2026-07-21
> **Baseline:** `master @ 6d50506`
> **Plans reviewed:** `08-driver-architecture.md`, `09-capability-model.md`
> **Implementation reviewed:** `src/Driver/`, `src/Platform/`, `src/Capabilities/`, `src/Contracts/Driver/`, `src/Contracts/Platform/`, `src/Contracts/Capabilities/`

---

## 1. Gaps

- **CRITICAL — Oracle platform and driver entirely absent.** Plan 08 §5 lists `OraclePlatform` (double-quote identifiers, rownum pagination, CONNECT BY); plan 09 §6 carries a full Oracle column in the capability matrix; plan 19 §2 shows `src/Driver/Oracle/` and `src/Platform/Oracle/` in the tree. Reality: both directories contain **only `.gitkeep`**. No `OraclePlatform`, no `OracleDriver`, no Oracle introspection dialect, no Oracle capability matrix — `grep "Oracle"` over `src/` finds nothing. Per `docs/PROGRESS.md` M8 T4, Oracle was "deferred" at the M8 gate; in code, deferral means empty placeholders. This leaves v1 at **5 of the 6 engines** the roadmap (23 M8: "full six-engine coverage … completes the full six-engine v1 promise") committed to. (Cross-ref: [08](08-testing-performance-roadmap.md) for the stale CI matrix.)

- **MINOR — `AbstractDriver` helper absent.** Plan 08 §2/§12: "A `AbstractDriver` helper is provided but optional." No such class in `src/Driver/`. The plan marks it optional, so this is a soft gap; built-in drivers implement `DriverInterface` directly.

- **MINOR — CockroachDB flavor absent.** Plan 08 §6 designs `CockroachDbPlatform extends PostgreSQLPlatform` with flavor `'cockroach'`. Not implemented — and `PostgreSQLPlatform` is `final` (see Drift), so the documented extension path is structurally blocked. CockroachDB was never promised for v1, but the flavor-subclass story now only works for the MySQL family.

## 2. Drift

- **MODERATE — `DriverRegistry` is instance-based, not static.** Plan 08 §8 specifies a static registry (`private static array $drivers`, static `register()`/`get()`/`getRegisteredNames()`). Implementation (`src/Driver/DriverRegistry.php`) is an instance service: constructor takes `iterable $drivers = []`, instance methods thereafter. This is arguably an improvement (DI-friendly, testable, no shared mutable static state), but it is a drift — and plan 08 §8's "built-in auto-registration" (the `SQLCraftFactory` pre-registers the 6 built-in drivers; see also 18 §3.1) has no replacement: the registry constructor registers **nothing by default**, so consumers must inject built-ins manually. (Cross-ref: [07](07-security-events-plugins-api.md) for the absent factory.)

- **MINOR — Per-engine subdirectory layout not used.** Plan 19 §2 shows `src/Driver/{MySQL,PostgreSQL,SQLite,SqlServer,Oracle}/` and the same under `src/Platform/`. All five subdirectories under each exist but contain only `.gitkeep`; the actual classes sit flat at `src/Driver/` and `src/Platform/` roots (`MySQLDriver.php`, `MySQLPlatform.php`, etc.).

- **MINOR — Platform finality narrows the flavor extension story.** Plan 08 §6 relies on subclassing for flavors. Actual declarations: `MySQLPlatform` non-final (✓ `MariaDbPlatform extends MySQLPlatform`), but `PostgreSQLPlatform`, `SqlServerPlatform`, `SqlitePlatform`, and `MariaDbPlatform` itself are all `final`. Third parties cannot subclass these to create flavors (e.g., CockroachDB, or a Postgres-variant) without forking.

- **MINOR (plan-internal; code correct) — `CapabilityNotSupportedException` namespace.** Lives in `SQLCraft\Capabilities` (`src/Capabilities/CapabilityNotSupportedException.php`), matching plan 09 §10's full code sketch verbatim. Plan 05 §9's hierarchy diagram implies `Exceptions\`; 09 §10 is authoritative and the code follows it. (Cross-ref: [01](01-domain-model.md).)

## 3. Extras

- **`PlatformInterface` gains two methods** beyond plan 08 §3.4: `getOperators(): list<string>` and `getSupportedAggregateFunctions(): list<string>` — from the security/query plans (15 §5.1, 12 §7), used by `OperatorValidator` and `SelectQueryRenderer`.
- **`CapabilitySet` carries event context.** Constructor additionally accepts `?SchemaEventDispatcherInterface $events`, `string $platform`, `string $version`; `require()` emits `capabilityNotSupported(...)` before throwing `CapabilityNotSupportedException` (events plan 16). Plan 09 §3's sketch had neither.
- **`PlatformCapabilityResolver` accepts the event dispatcher** and passes it through to `CapabilitySet`; also de-duplicates with `array_unique(..., SORT_REGULAR)`.
- **`ExtendedCapability` VO** exists per plan 09 §8 (`src/Capabilities/ExtendedCapability.php`) — planned, listed here only to confirm presence.
- **`getServerVersion()` fallback:** `MySQLPlatform::getServerVersion()` falls back to `ServerVersion('5.7.0')` when `SELECT VERSION()` yields nothing — unspecified defensive behavior.

## 4. Faithful to Plan

- **`Capability` enum matches plan 09 §2 exactly:** all 35 cases with identical string values (`table`, `view`, `materializedview`, `sequence`, `type`, `scheme`, `columns`, `comment`, `charset`, `collation`, `compression`, `generated`, `indexes`, `fkeys`, `check`, `partial_indexes`, `descidx`, `copy`, `insert_update`, `drop_col`, `move_col`, `database`, `routine`, `procedure`, `trigger`, `view_trigger`, `event`, `status`, `variables`, `processlist`, `kill`, `privileges`, `sql`, `dump`, `partitions`).
- **`CapabilitySet` API per plan 09 §3:** `has()` (strict `in_array`), `require()` (throws `CapabilityNotSupportedException::for(...)`), `intersect()`, `toArray()`, `IteratorAggregate` + `Countable`. Immutable (`final readonly`).
- **`PlatformCapabilityResolver` per plan 08 §7:** consumes the `array{always?: list<Capability>, versioned?: list<array{Capability, array{int,int,int}}>}` matrix shape and evaluates `ServerVersion::isAtLeast()` predicates — the static-matrix-plus-version-predicates decision (09 §4) honored.
- **`MySQLPlatform::buildCapabilityMatrix()` matches plan 09 §6's matrix:** always-on includes `Compression` and `Partitions` (both "Yes" for MySQL in §6, beyond the §7 example list); versioned gates are `GeneratedColumns ≥ 5.7.0`, `DescendingIndexes ≥ 8.0.0`, `CheckConstraints ≥ 8.0.16` — exactly the §6 rows.
- **`DriverInterface` matches plan 08 §2 verbatim:** `buildDsn(ConnectionParameters): string`, `connect(ConnectionParameters): ConnectionInterface`, `getPlatform(ConnectionInterface): PlatformInterface`, `getName(): string`, `getPdoDriverNames(): list<string>`.
- **Segregated platform interfaces per plan 08 §3:** all five exist in `src/Contracts/Platform/` — `QuotingInterface`, `PaginationInterface`, `TypeMapperInterface`, `DdlDialectInterface`, `IntrospectionDialectInterface` — and `PlatformInterface extends` all five plus the eight planned methods (`getName`, `getFlavor`, `getServerVersion`, `getCapabilitySet`, `getDefaultCharset`, `getDefaultCollation`, `supportsSchemas`, `getKeywordList`).
- **Flavor decision honored:** `final class MariaDbPlatform extends MySQLPlatform` (`src/Platform/MariaDbPlatform.php:9`) overrides `buildCapabilityMatrix()` via `parent::` + deltas, per 08 §6's "subclass with contained branching".
- **`AbstractPlatform` template method per plan 08 §4:** `abstract protected function buildCapabilityMatrix(): array` (`AbstractPlatform.php:479`); `getCapabilitySet()` wires the resolver (`AbstractPlatform.php:464`); SQL-standard quoting default with MySQL backtick override.
- **Five working platform/driver stacks:** `MySQLPlatform`/`MariaDbPlatform`/`PostgreSQLPlatform`/`SqlitePlatform`/`SqlServerPlatform` and `MySQLDriver`/`PostgreSQLDriver`/`SqliteDriver`/`SqlServerDriver` + `DriverRegistry`.
- **`DriverNotFoundException::forName`-style typed error** on unknown driver names (`DriverRegistry::get()`).

## 5. Summary

The capability model is essentially a verbatim realization of plan 09 — enum, set, resolver, matrix shape, and MySQL's matrix all match — and the segregated platform/driver interfaces match plan 08 exactly. The headline gap is Oracle: entirely absent against plans 08/09/19 and the roadmap's six-engine promise, with only `.gitkeep` placeholders to show for it. Secondary drifts are the instance-based (rather than static) `DriverRegistry` with no built-in pre-registration, the unused per-engine directory layout, and platform finality that narrows the documented flavor-subclass extension path.
