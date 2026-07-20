# 09 — Capability Model

> **Status:** Design draft  
> **Scope:** `Capability` enum, `CapabilitySet` VO, version-aware resolver, unsupported-feature surface, capability matrix, service gating, extensibility  
> **Replaces:** Adminer's `support(string $feature): bool|string` with unchecked string keys and `preg_match` version detection

---

## 1. The Problem with Adminer's Approach

Adminer gates features with a single function:

```php
// Adminer — do NOT copy this pattern
function support(string $feature): bool|string {
    global $driver;
    return isset($driver->support[$feature]);
}
```

And version gating is ad-hoc:

```php
// MySQL check constraint support check — scattered in adminer.php
if (preg_match('~^(8\.0\.(1[6-9]|[2-9]\d)|[89]\.|[1-9]\d\.)~', $connection->server_info)) { ... }
```

Problems:

1. **Typo-unsafe.** `support("privelege")` silently returns false instead of a compile error.
2. **Unchecked string coupling.** Every caller must know the exact string key. No IDE autocomplete, no static analysis.
3. **Version detection scattered.** Regex patterns duplicated across files; no single source of truth for "MySQL ≥8.0.16 supports CHECK".
4. **No structured reason.** A caller cannot distinguish "not supported by this engine" from "not supported by this version" from "not implemented yet".
5. **No discovery.** A UI cannot enumerate supported capabilities without reading source code.
6. **No extensibility.** Adding a capability for a new driver means touching the capability check code everywhere it is referenced.

SQLCraft replaces this with a **type-safe, version-aware, discoverable capability system**.

---

## 2. The `Capability` Enum

```php
namespace SQLCraft\Capabilities;

/**
 * Every named feature that a platform may or may not support.
 * Backed by string for serialisation and logging readability.
 * Names match Adminer's support() strings where equivalent, extended where needed.
 */
enum Capability: string
{
    // Schema objects
    case Table             = 'table';
    case View              = 'view';
    case MaterializedView  = 'materializedview';
    case Sequence          = 'sequence';
    case Type              = 'type';         // CREATE TYPE (PgSQL)
    case Scheme            = 'scheme';       // named schemas/namespaces

    // Column / table features
    case Columns           = 'columns';
    case Comment           = 'comment';      // object comments
    case Charset           = 'charset';
    case Collation         = 'collation';
    case Compression       = 'compression';
    case GeneratedColumns  = 'generated';

    // Constraint features
    case Indexes           = 'indexes';
    case ForeignKeys       = 'fkeys';
    case CheckConstraints  = 'check';
    case PartialIndexes    = 'partial_indexes';
    case DescendingIndexes = 'descidx';

    // DML features
    case Copy              = 'copy';         // CREATE TABLE ... SELECT
    case InsertUpdate      = 'insert_update'; // ON DUPLICATE KEY / UPSERT

    // DDL structural
    case DropColumn        = 'drop_col';
    case MoveColumn        = 'move_col';     // FIRST / AFTER
    case Database          = 'database';     // CREATE/DROP DATABASE

    // Routines / programmability
    case Routine           = 'routine';      // functions
    case Procedure         = 'procedure';
    case Trigger           = 'trigger';
    case ViewTrigger       = 'view_trigger'; // triggers on views (MSSQL)
    case Event             = 'event';        // MySQL/MariaDB scheduler events

    // Introspection / admin
    case Status            = 'status';       // SHOW TABLE STATUS equivalent
    case Variables         = 'variables';    // SHOW VARIABLES equivalent
    case Processlist       = 'processlist';
    case Kill              = 'kill';         // kill a connection/query
    case Privileges        = 'privileges';   // GRANT/REVOKE
    case Sql               = 'sql';          // arbitrary SQL execution
    case Dump              = 'dump';         // export
    case Partitions        = 'partitions';

    // Unsupported marker (used internally, not in CapabilitySet)
    // Extended capabilities added by third-party drivers use their own enum
    // via the CapabilitySet::withExtended() API (see §7).
}
```

**Decision — backed enum vs interface constants:** A backed `enum` was chosen over `interface` constants (Adminer's implicit model) because:
- Enums are first-class types; you cannot pass an arbitrary string where `Capability` is expected.
- PHPStan/Psalm track exhaustiveness on `match` expressions over enum cases.
- Enums are serialisable (via `->value`) and comparable by identity.
- An `enum` cannot be accidentally extended by `implements`, preserving the closed set within the SQLCraft core.

**Decision — extensibility of a closed enum:** PHP enums cannot be extended. Third-party capabilities use a separate `ExtendedCapability` value object (see §7) that wraps a string. The `CapabilitySet` accepts both `Capability` and `ExtendedCapability` via a union type.

---

## 3. The `CapabilitySet` Value Object

`CapabilitySet` is an **immutable, queryable container** of capabilities that apply to a specific platform at a specific server version.

```php
namespace SQLCraft\Capabilities;

/**
 * Immutable set of capabilities for a platform+version combination.
 * Returned by CapabilityResolverInterface::resolve().
 *
 * @template-implements \IteratorAggregate<Capability|ExtendedCapability>
 */
final readonly class CapabilitySet implements \IteratorAggregate, \Countable
{
    /** @param list<Capability|ExtendedCapability> $capabilities */
    public function __construct(private array $capabilities) {}

    public function has(Capability|ExtendedCapability $capability): bool
    {
        return in_array($capability, $this->capabilities, strict: true);
    }

    /**
     * Require a capability or throw CapabilityNotSupportedException.
     * Application services call this as a guard clause.
     */
    public function require(Capability|ExtendedCapability $capability): void
    {
        if (!$this->has($capability)) {
            throw CapabilityNotSupportedException::for($capability);
        }
    }

    /** Return a new set that is the intersection of this and another. */
    public function intersect(self $other): self
    {
        return new self(array_values(array_filter(
            $this->capabilities,
            fn($c) => $other->has($c),
        )));
    }

    /** Return all capability values for serialisation / discovery. */
    public function toArray(): array { return $this->capabilities; }

    public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->capabilities); }
    public function count(): int { return count($this->capabilities); }
}
```

**Why immutable:** Once resolved for a server version, the set does not change. Mutable sets would allow partial construction and introduce guard-clause ordering bugs.

**Why not a bitmask:** PHP has no 64-bit safe enum-backed bitmask ergonomics. Arrays with strict identity checks are simpler, PHPStan-friendly, and performance-equivalent for sets of ≤60 items.

---

## 4. Version-Aware Capability Resolver

```php
namespace SQLCraft\Contracts\Capabilities;

interface CapabilityResolverInterface
{
    /**
     * Resolve capabilities for a platform at a given server version.
     * May introspect the live connection for fine-grained version detection.
     */
    public function resolve(
        string        $platformName,
        ServerVersion $version,
        ?ConnectionInterface $connection = null,
    ): CapabilitySet;
}
```

The concrete `PlatformCapabilityResolver` holds a static matrix (see §6) plus version predicates:

```php
// Internal structure of MySQLCapabilityResolver
private function resolveMySQL(ServerVersion $v): CapabilitySet
{
    $caps = [
        Capability::Table, Capability::View, Capability::Columns,
        Capability::Indexes, Capability::ForeignKeys, Capability::Sql,
        Capability::Database, Capability::DropColumn, Capability::Dump,
        // ...always-on capabilities
    ];

    if ($v->isAtLeast(8, 0, 16)) {
        $caps[] = Capability::CheckConstraints;
    }
    if ($v->isAtLeast(8, 0)) {
        $caps[] = Capability::DescendingIndexes;
    }
    // MariaDB check constraints: version ≥10.2.1 (handled by MariaDB flavor resolver)
    return new CapabilitySet($caps);
}
```

**Decision — static matrix vs dynamic discovery:** A static matrix with version predicates is chosen over live `INFORMATION_SCHEMA` queries because:
1. Capability resolution must be fast (zero extra round trips).
2. `INFORMATION_SCHEMA` availability is itself capability-dependent.
3. The static matrix is testable without a live database.
4. The optional `$connection` parameter allows dynamic refinement for edge cases (e.g., reading MySQL's `@@innodb_compression_level` to confirm Compression support).

---

## 5. Surfacing Unsupported Capabilities

Three patterns are considered when a caller requests a capability the platform lacks:

### 5.1 Typed Exception (Recommended)

```php
// Application service guard pattern
public function listTriggers(ConnectionInterface $conn, QualifiedName $table): TriggerCollection
{
    $caps = $this->capabilityResolver->resolve(
        $conn->getPlatformName(), $conn->getServerVersion()
    );
    $caps->require(Capability::Trigger); // throws CapabilityNotSupportedException if absent

    return $this->metadataProvider->getTriggers($conn, $table);
}
```

`CapabilityNotSupportedException` carries the `Capability` value, the platform name, and the version — so a caller can log "Oracle does not support triggers" rather than a generic error.

### 5.2 Boolean Check (for conditional UI/logic)

```php
if ($caps->has(Capability::MaterializedView)) {
    $this->showMaterializedViewTab();
}
```

### 5.3 Result<T, CapabilityError> (Rejected)

A functional-style `Result` monad was considered but rejected:
- PHP lacks native Result types; introducing one adds a dependency or custom implementation.
- Callers must always unwrap, adding boilerplate without benefit over a typed exception in 95% of cases.
- The exception approach integrates naturally with existing PHP error handling and PSR-3 logging.

**Recommendation:** Use `$caps->require(...)` (exception) at service entry points. Use `$caps->has(...)` (boolean) in UI-driving code and for optional optimisations. Never silently degrade.

---

## 6. Capability Matrix

Full matrix for the initial 6 engines. "Yes" = always supported. "No" = never supported. Version strings indicate minimum version requirement.

| Capability | MySQL | MariaDB | PostgreSQL | SQLite | MS SQL Server | Oracle |
|------------|-------|---------|------------|--------|---------------|--------|
| `table` | Yes | Yes | Yes | Yes | Yes | Yes |
| `view` | Yes | Yes | Yes | Yes | Yes | Yes |
| `columns` | Yes | Yes | Yes | Yes | Yes | Yes |
| `indexes` | Yes | Yes | Yes | Yes | Yes | Yes |
| `fkeys` | Yes | Yes | Yes | Yes | Yes | Yes |
| `sql` | Yes | Yes | Yes | Yes | Yes | Yes |
| `database` | Yes | Yes | Yes | Yes | Yes | Yes |
| `drop_col` | Yes | Yes | Yes | Yes | Yes | Yes |
| `dump` | Yes | Yes | Yes | Yes | Yes | Yes |
| `comment` | Yes | Yes | Yes | No | Yes | No |
| `status` | Yes | Yes | No | Yes | Yes | Yes |
| `variables` | Yes | Yes | No | Yes | Yes | Yes |
| `processlist` | Yes | Yes | Yes | No | No | Yes |
| `kill` | Yes | Yes | Yes | No | No | No |
| `privileges` | Yes | Yes | No | No | No | No |
| `trigger` | Yes | Yes | Yes | Yes | Yes | No |
| `view_trigger` | No | No | No | No | Yes | No |
| `routine` | Yes | Yes | Yes | No | No | No |
| `procedure` | Yes | Yes | 11.0+ | No | No | No |
| `event` | Yes | Yes | No | No | No | No |
| `sequence` | No | 10.3+ | Yes | No | No | Yes |
| `scheme` | No | No | Yes | No | Yes | Yes |
| `type` | No | No | Yes | No | No | No |
| `materializedview` | No | No | 9.3+ | No | No | No |
| `check` | 8.0.16+ | 10.2.1+ | Yes | Yes | Yes | No |
| `descidx` | 8.0+ | Yes | Yes | Yes | Yes | Yes |
| `partial_indexes` | No | No | Yes | Yes | No | No |
| `copy` | Yes | Yes | No | No | No | No |
| `move_col` | Yes | Yes | No | No | No | No |
| `insert_update` | Yes | Yes | No | Yes | No | No |
| `compression` | Yes | Yes | No | No | No | No |
| `generated` | 5.7+ | 5.2+ | 12.0+ | 3.31+ | Yes | Yes |
| `partitions` | Yes | Yes | Yes (native) | No | Yes | Yes |

**Notes:**
- PostgreSQL `procedure` requires server ≥11 (before 11, only functions exist).
- PostgreSQL `processlist` works via `pg_stat_activity`; CockroachDB flavor overrides this to No.
- MariaDB `sequence` requires ≥10.3.0.
- SQLite has no server version concept; `generated` support via `sqlite3_libversion()` check ≥3.31.0.
- Oracle `sequence` support is always-on (Oracle has had sequences since very early versions).

---

## 7. Gating Service Methods

The `requireCapability()` guard pattern is the standard:

```php
// In MetadataService
public function getCheckConstraints(ConnectionInterface $conn, QualifiedName $table): array
{
    $this->caps($conn)->require(Capability::CheckConstraints);
    return $this->provider->fetchCheckConstraints($conn, $table);
}

private function caps(ConnectionInterface $conn): CapabilitySet
{
    return $this->resolver->resolve(
        $conn->getPlatformName(),
        $conn->getServerVersion(),
    );
}
```

For methods that have a partial fallback (e.g., `getIndexes` always works but `descidx` enables DESCENDING INDEX direction), a `has()` check gates the extra field:

```php
$meta = $this->provider->fetchIndexes($conn, $table);
if (!$this->caps($conn)->has(Capability::DescendingIndexes)) {
    // Zero out descending flags — DB returned them but platform can't use them
    $meta = $meta->withoutDescendingInfo();
}
```

---

## 8. Extensibility

### Adding a New Capability for an Existing Engine

1. Add a case to `Capability` enum: `case Replication = 'replication';`
2. Add it to the relevant platform's `buildCapabilityMatrix()` array.
3. No consumers break — they only call `$caps->has(Capability::Replication)`.

### Third-Party / Extended Capabilities

For capabilities specific to a third-party driver (not part of SQLCraft core), use `ExtendedCapability`:

```php
namespace Acme\SQLCraftDuckDb;

final readonly class DuckDbCapability
{
    public static function parquet(): ExtendedCapability
    {
        return new ExtendedCapability('duckdb.parquet');
    }
}

// Usage:
$caps->has(DuckDbCapability::parquet()); // bool
$caps->require(DuckDbCapability::parquet()); // throws if absent
```

`ExtendedCapability` is a simple readonly wrapper:

```php
final readonly class ExtendedCapability
{
    public function __construct(public readonly string $name) {}
    public function equals(self $other): bool { return $this->name === $other->name; }
}
```

### Capability Discovery (for UI consumers)

A UI built on SQLCraft needs to know which actions to show. `CapabilitySet::toArray()` returns all active capabilities as an array, which a UI can map to visibility flags:

```php
$caps = $resolver->resolve($platform, $version);
$supportsRoutines = $caps->has(Capability::Routine);
$supportsTriggers = $caps->has(Capability::Trigger);
// UI renders "Routines" tab only if $supportsRoutines === true
```

This replaces the Adminer pattern of calling `support("routine")` inside every template branch.

---

## 9. Contrast with Adminer's `support()` Approach

| Concern | Adminer | SQLCraft |
|---------|---------|----------|
| Type safety | String; typo compiles | Enum case; typo is parse error |
| IDE support | No autocomplete | Full autocomplete + PHPStan exhaustiveness |
| Version detection | `preg_match` scattered in code | Central `PlatformCapabilityResolver` with version predicates |
| Discoverability | Read source to find all strings | `Capability::cases()` lists everything |
| Error on missing | Silent `false` | Typed `CapabilityNotSupportedException` with context |
| Third-party extensions | Add string constant | `ExtendedCapability` VO + driver's `buildCapabilityMatrix()` |
| Testing | String matching | Enum identity; mock `CapabilityResolverInterface` |
| Multi-engine matrix | Per-file scattered checks | Single `buildCapabilityMatrix()` per platform |

**Concrete improvement example:**

Adminer MySQL check constraint detection:
```php
// Adminer — preg_match version detection, file adminer.php ~line 2300
if (preg_match('~^(8\.0\.(1[6-9]|[2-9]\d)|[89]\.|[1-9]\d\.)~', $connection->server_info)) {
    // show check constraint UI
}
```

SQLCraft equivalent (no regex, no version string parsing at call site):
```php
if ($caps->has(Capability::CheckConstraints)) {
    // show check constraint UI
}
```

The capability resolver encapsulates the version predicate once; every consumer gets a boolean with no knowledge of version strings.

---

## 10. `CapabilityNotSupportedException`

```php
namespace SQLCraft\Capabilities;

use SQLCraft\Exceptions\CapabilityException;

final class CapabilityNotSupportedException extends CapabilityException
{
    public function __construct(
        public readonly Capability|ExtendedCapability $capability,
        public readonly string $platform,
        public readonly string $version = '',
    ) {
        $capabilityName = $capability instanceof Capability
            ? $capability->value
            : $capability->name;
        $context = $platform === ''
            ? $capabilityName
            : sprintf('%s on %s%s', $capabilityName, $platform, $version === '' ? '' : ' ' . $version);
        parent::__construct(sprintf('Capability not supported: %s.', $context));
    }

    public static function for(
        Capability|ExtendedCapability $capability,
        string $platform = '',
        string $version = '',
    ): self {
        return new self($capability, $platform, $version);
    }
}
```

This enables callers to distinguish "unsupported capability" from generic errors, log structured information, and build user-facing messages ("This database version does not support CHECK constraints. Upgrade to MySQL 8.0.16+.").

---

## 11. Granular vs Coarse Capabilities — Tradeoff

Adminer's `support()` strings are already fairly granular (33 distinct flags). SQLCraft could go further and split, e.g., `Capability::Trigger` into `TriggerBefore`, `TriggerAfter`, `TriggerInsteadOf`. This was considered and **rejected** for the initial capability set:

**Arguments for finer granularity:**
- MSSQL supports `INSTEAD OF` triggers on views but not all timings on all object types.
- Would let a consumer precisely gate UI per timing.

**Arguments for coarser granularity (chosen):**
- Explosion of enum cases (33 → 100+) makes the matrix unwieldy and harder to review.
- Most consumers only need "does this engine support triggers at all" — fine-grained timing support is already expressed via `TriggerTiming`/`TriggerEvent` enums at the VO level, not the capability level.
- Finer flags increase the chance of over-specification: a capability check for a combination that no real engine actually restricts.

**Resolution:** Capabilities gate *object-level and feature-level* support (whole trigger mechanism, whole check-constraint mechanism). Fine-grained restrictions within a supported feature (e.g., "PgSQL triggers can't be INSTEAD OF on tables, only views") are surfaced as validation errors at DDL-build time via `DdlBuilder`, not as additional capability enum cases. This keeps the capability model focused on "can I use this feature at all" rather than every combinatorial restriction.

If future evidence shows real consumer need for finer flags (e.g., a UI wants to grey out just the "INSTEAD OF" option), a targeted `ExtendedCapability` can be added without enum explosion, per §8.

---

## 12. Summary of Key Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Enum vs interface constants | Backed `enum Capability` | Type-safe, exhaustive `match`, no arbitrary strings |
| Extended capabilities | Separate `ExtendedCapability` VO | PHP enums are closed; avoids forcing core changes for third parties |
| Surfacing unsupported | Typed exception (`require()`) + boolean (`has()`) | Exceptions for guard clauses, booleans for UI/optional paths |
| Resolution strategy | Static matrix + version predicates | Fast, testable offline, no extra round trips |
| Granularity | Coarse (feature-level), not per-timing/per-variant | Avoids enum explosion; fine restrictions handled at DDL validation time |
| Set representation | Array with strict `in_array` | Simpler and equally performant vs bitmask for ≤60 items |

