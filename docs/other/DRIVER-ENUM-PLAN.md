# Implementation Plan: First-Class `DatabaseDriver` Enum on `ConnectionParameters`

**Status:** Planning  
**Author:** Kiro  
**Target files:** 8 PHP files + 1 documentation file  

---

## 1. Background and Motivation

`ConnectionParameters` is a value object that carries all parameters needed to
open a database connection. Currently the driver selection is hidden inside the
free-form `extras` bag:

```php
// Current API
new ConnectionParameters(
    host: 'localhost',
    database: 'shop',
    extras: ['driver' => 'mysql'],   // ← buried, stringly typed
)
```

`SQLCraftFactory::session()` extracts it with an unsafe cast:

```php
// SQLCraftFactory.php line 112
$driverName = (string) ($parameters->extras['driver'] ?? 'sqlite');
```

This has several concrete problems:

- The property is invisible to static analysis and IDEs — there is no
  autocomplete, no type-check, no "go to definition."
- A typo (`'msyql'`, `'postresql'`) produces a runtime `DriverNotFoundException`
  instead of a parse-time or IDE error.
- The implicit fallback `?? 'sqlite'` silently masks a missing driver key when
  using the factory; the caller may not realise the wrong engine is selected.
- `extras` is documented as "driver-specific options" (key–value pairs passed
  through to the engine), but the driver *selector* is not an engine option — it
  is meta-information about which engine to use at all.
- `DriverNotFoundException::$driver` already stores a plain string; with an enum
  the exception can carry a typed, validated value.

---

## 2. Design Decisions

### 2.1 Enum name and location

| Decision | Choice | Rationale |
|---|---|---|
| Type | `enum DatabaseDriver: string` | String-backed so `->value` produces the registry key with zero adapter code |
| Namespace | `SQLCraft\Enums\DatabaseDriver` | Mirrors the existing `SQLCraft\Capabilities\Capability` enum pattern; keeps the root namespace clean |
| File | `src/Enums/DatabaseDriver.php` | New `Enums/` directory next to `Capabilities/` |

### 2.2 Enum cases and backing values

The backing values **must match** what `DriverInterface::getName()` returns on
each concrete driver, because `DriverRegistry::get(string $name)` is the lookup
key. Changing the backing values would require touching every driver's
`getName()` — unnecessary blast radius.

```php
enum DatabaseDriver: string
{
    case MySQL      = 'mysql';
    case MariaDB    = 'mariadb';   // alias registered in SQLCraftFactory
    case PostgreSQL = 'pgsql';     // matches PostgreSQLDriver::getName()
    case SQLite     = 'sqlite';
    case SqlServer  = 'sqlserver';
}
```

`MariaDB` deserves its own case because it is a first-class alias in
`DriverRegistry` (registered in `SQLCraftFactory`) and callers may reasonably
want to express "I am connecting to MariaDB specifically" for documentation or
tooling purposes, even though it routes to the same `MySQLDriver` instance.

### 2.3 Nullability of the new property

`ConnectionParameters` is used in two distinct contexts:

1. **Via `SQLCraftFactory::session()`** — the driver must be specified; the
   factory routes to the correct `DriverInterface`.
2. **Direct driver instantiation** — e.g. `new MySQLDriver(...)->connect($params)`.
   In this path the driver is already chosen at the call site; the property is
   irrelevant.

Making `driver` nullable (`?DatabaseDriver $driver = null`) keeps the value
object usable in both paths without forcing callers to pass a dummy value.
`SQLCraftFactory::session()` will throw `\InvalidArgumentException` when
`$parameters->driver` is `null`.

**Do not** keep the `?? 'sqlite'` silent fallback. The implicit default was a
design smell; surfacing it as an explicit error improves debuggability.

### 2.4 What stays in `extras`

`extras` continues to carry genuinely driver-specific options that are passed
through to the connection (e.g. `applicationName`, `init_command`, `options`,
`encrypt`). Only the driver selector is promoted. The `extras` array type
annotation does not change.

### 2.5 `DriverRegistry` — string vs enum

`DriverRegistry::get(string $name)` is an internal API. A typed overload
`getByDriver(DatabaseDriver $driver): DriverInterface` makes `SQLCraftFactory`
read more cleanly and prevents accidental misuse at call sites:

```php
// Before
$connection = $this->drivers->get($driverName)->connect($parameters);

// After
$connection = $this->drivers->getByDriver($parameters->driver)->connect($parameters);
```

The original `get(string $name)` stays — it is still needed for
`registerAlias()` resolution and potential third-party custom drivers.

### 2.6 Backward compatibility

This is a **breaking change** to the public constructor signature of
`ConnectionParameters`. Concretely:

- Any caller using `extras: ['driver' => '...']` with `SQLCraftFactory` will
  receive an `\InvalidArgumentException` at runtime until migrated.
- Callers that construct `ConnectionParameters` for direct driver use (no
  factory) are unaffected if they do not pass `driver`.

A named constructor `ConnectionParameters::fromLegacyArray(array $config)` is
**out of scope** for this change (can be noted in DEFERRED-FEATURES.md if desired).

Recommend a dedicated CHANGELOG entry and a minor-version or major-version bump
depending on the project's versioning policy.

---

## 3. File-by-File Changes

### 3.1 NEW — `src/Enums/DatabaseDriver.php`

Create this file from scratch.

```php
<?php

declare(strict_types=1);

namespace SQLCraft\Enums;

enum DatabaseDriver: string
{
    case MySQL      = 'mysql';
    case MariaDB    = 'mariadb';
    case PostgreSQL = 'pgsql';
    case SQLite     = 'sqlite';
    case SqlServer  = 'sqlserver';
}
```

No additional methods are needed at this stage; `->value` and `::from()` /
`::tryFrom()` are provided by PHP natively.

---

### 3.2 MODIFIED — `src/ValueObjects/ConnectionParameters.php`

**Add import:**

```diff
 use InvalidArgumentException;
 use SensitiveParameter;
+use SQLCraft\Enums\DatabaseDriver;
 use SQLCraft\Support\StringUtil;
```

**Add `driver` as the last constructor parameter** (preserves backward
compatibility with all existing named-argument call sites):

```diff
     public function __construct(
         public ?string $host = null,
         public ?int $port = null,
         public ?string $socket = null,
         public ?string $database = null,
         public ?string $username = null,
         #[SensitiveParameter]
         public ?string $password = null,
         public ?string $charset = null,
         public array $ssl = [],
         public array $extras = [],
+        public ?DatabaseDriver $driver = null,
     ) {
```

No validation change needed for `driver`; `null` is the valid "unspecified"
sentinel for direct-driver callers.

---

### 3.3 MODIFIED — `src/Driver/DriverRegistry.php`

**Add import:**

```diff
+use SQLCraft\Enums\DatabaseDriver;
 use SQLCraft\Exceptions\DriverNotFoundException;
```

**Add typed convenience method after `get()`:**

```diff
     public function get(string $name): DriverInterface
     {
         return $this->drivers[$name]
             ?? throw new DriverNotFoundException(sprintf('Driver not found: %s.', $name), $name);
     }

+    public function getByDriver(DatabaseDriver $driver): DriverInterface
+    {
+        return $this->get($driver->value);
+    }
+
     /** @return list<string> */
```

---

### 3.4 MODIFIED — `src/SQLCraftFactory.php`

**Add import:**

```diff
+use SQLCraft\Enums\DatabaseDriver;
```

**Change A — credential copy block: forward the new property (lines 99–110):**

```diff
             $parameters = new ConnectionParameters(
                 host: $parameters->host,
                 port: $parameters->port,
                 socket: $parameters->socket,
                 database: $parameters->database,
                 username: $credential->username,
                 password: $credential->password,
                 charset: $parameters->charset,
                 ssl: $parameters->ssl,
                 extras: $parameters->extras,
+                driver: $parameters->driver,
             );
```

**Change B — replace the stringly-typed extras lookup (line 112):**

```diff
-        $driverName = (string) ($parameters->extras['driver'] ?? 'sqlite');
-        $connection = $this->drivers->get($driverName)->connect($parameters);
-        $connectionName = $name ?? $connection->getName() ?? $driverName;
+        if ($parameters->driver === null) {
+            throw new \InvalidArgumentException(
+                'ConnectionParameters::$driver must be set when using SQLCraftFactory::session(). '
+                . 'Pass a DatabaseDriver enum case, e.g. driver: DatabaseDriver::SQLite.'
+            );
+        }
+        $connection = $this->drivers->getByDriver($parameters->driver)->connect($parameters);
+        $connectionName = $name ?? $connection->getName() ?? $parameters->driver->value;
```

---

### 3.5 MODIFIED — `src/Exceptions/DriverNotFoundException.php`

Widen the `$driver` property to accept a typed enum in addition to the existing
string path (used by custom/external drivers not in the enum):

```diff
+use SQLCraft\Enums\DatabaseDriver;

     public function __construct(
         string $message,
-        public readonly string $driver = '',
+        public readonly string|DatabaseDriver $driver = '',
         int $code = 0,
         ?\Throwable $previous = null,
     ) {
```

---

### 3.6 MODIFIED — `src/Exceptions/DriverMisconfiguredException.php`

Same widening as `DriverNotFoundException`:

```diff
+use SQLCraft\Enums\DatabaseDriver;

     public function __construct(
         string $message,
-        public readonly string $driver = '',
+        public readonly string|DatabaseDriver $driver = '',
         int $code = 0,
         ?\Throwable $previous = null,
     ) {
```

---

## 4. Test Changes

### 4.1 MODIFIED — `tests/Unit/ValueObjects/NamedAndConnectionValueObjectsTest.php`

Add `DatabaseDriver` import and extend the existing
`testConnectionParametersStoresConnectionOptions` test:

```diff
+use SQLCraft\Enums\DatabaseDriver;

     $parameters = new ConnectionParameters(
         host: 'db.internal',
         ...
         extras: ['applicationName' => 'sqlcraft'],
+        driver: DatabaseDriver::MySQL,
     );

+    self::assertSame(DatabaseDriver::MySQL, $parameters->driver);
```

Add a new test for the null default:

```php
public function testConnectionParametersDefaultsDriverToNull(): void
{
    $parameters = new ConnectionParameters(database: 'shop');

    self::assertNull($parameters->driver);
}
```

### 4.2 NEW — `tests/Unit/Enums/DatabaseDriverTest.php`

```php
<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use SQLCraft\Enums\DatabaseDriver;

final class DatabaseDriverTest extends TestCase
{
    public function testAllCasesHaveExpectedBackingValues(): void
    {
        self::assertSame('mysql',     DatabaseDriver::MySQL->value);
        self::assertSame('mariadb',   DatabaseDriver::MariaDB->value);
        self::assertSame('pgsql',     DatabaseDriver::PostgreSQL->value);
        self::assertSame('sqlite',    DatabaseDriver::SQLite->value);
        self::assertSame('sqlserver', DatabaseDriver::SqlServer->value);
    }

    public function testFromProducesCorrectCase(): void
    {
        self::assertSame(DatabaseDriver::MySQL,      DatabaseDriver::from('mysql'));
        self::assertSame(DatabaseDriver::MariaDB,    DatabaseDriver::from('mariadb'));
        self::assertSame(DatabaseDriver::PostgreSQL, DatabaseDriver::from('pgsql'));
        self::assertSame(DatabaseDriver::SQLite,     DatabaseDriver::from('sqlite'));
        self::assertSame(DatabaseDriver::SqlServer,  DatabaseDriver::from('sqlserver'));
    }

    public function testTryFromReturnsNullForUnknownValue(): void
    {
        self::assertNull(DatabaseDriver::tryFrom('oracle'));
        self::assertNull(DatabaseDriver::tryFrom(''));
    }
}
```

### 4.3 NEW — `tests/Unit/SQLCraftFactoryTest.php` (or augment existing)

Add a unit test asserting that `session()` throws when `driver` is null:

```php
use SQLCraft\Enums\DatabaseDriver;

public function testSessionThrowsWhenDriverIsNull(): void
{
    $factory = new SQLCraftFactory();

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('DatabaseDriver enum case');

    $factory->session(new ConnectionParameters(database: ':memory:'));
}
```

### 4.4 MODIFIED — `tests/Unit/Driver/DriverRegistryTest.php`

Add coverage for the new `getByDriver()` method:

```php
use SQLCraft\Enums\DatabaseDriver;

public function testGetByDriverDelegatesToGetWithBackingValue(): void
{
    $driver = $this->fakeDriver(); // getName() returns 'fake'; register under sqlite key
    $registry = new DriverRegistry();
    $registry->registerAlias('sqlite', $driver);

    self::assertSame($driver, $registry->getByDriver(DatabaseDriver::SQLite));
}
```

### 4.5 Contract tests — NO CHANGES NEEDED

All five conformance tests (`MySQLPlatformConformanceTest`, `PostgreSQL*`,
`Sqlite*`, `SqlServer*`, `MariaDb*`) construct `ConnectionParameters` with named
arguments and then call `$driver->connect($params)` directly, bypassing
`SQLCraftFactory` entirely. The new nullable `driver` property defaults to
`null`, so these tests continue to compile and pass without any edits.

---

## 5. Documentation Changes

### 5.1 MODIFIED — `docs/user-guide/connections.md`

There are approximately **15 call sites** in this file that pass
`extras: ['driver' => '...']`. Apply this pattern uniformly:

**Driver-only extras array:**

```diff
-extras: ['driver' => 'mysql']
+driver: DatabaseDriver::MySQL,
```

**Mixed extras (driver + engine options):**

```diff
-extras: [
-    'driver' => 'mysql',
-    'init_command' => 'SET sql_mode="STRICT_ALL_TABLES"'
-]
+driver: DatabaseDriver::MySQL,
+extras: ['init_command' => 'SET sql_mode="STRICT_ALL_TABLES"']
```

**Update the Connection Parameters table** to list `driver` as a first-class row:

| Parameter | Type | Description | Default |
|---|---|---|---|
| `driver` | `?DatabaseDriver` | Database engine (required when using `SQLCraftFactory`) | `null` |
| `extras` | `array` | Driver-specific pass-through options | `[]` |

**Replace the old "Driver Selection" prose block:**

```md
### Driver Selection

Pass a `DatabaseDriver` enum case:

use SQLCraft\Enums\DatabaseDriver;

$params = new ConnectionParameters(
    database: 'mydb',
    driver: DatabaseDriver::MySQL,
);

Available cases: `MySQL`, `MariaDB`, `PostgreSQL`, `SQLite`, `SqlServer`.
```

**Symfony YAML example** near the bottom currently shows
`extras: { driver: '%env(DB_DRIVER)%' }`. YAML cannot express a PHP enum
directly. Replace with a note:

```md
> **Note:** The `driver` parameter requires a `DatabaseDriver` enum case.
> In Symfony, resolve it in a tagged factory service using
> `DatabaseDriver::from($driverString)` rather than wiring it directly in YAML.
```

---

## 6. Example Script Changes

All eight example scripts (`examples/01` through `examples/08`) instantiate
drivers directly and never route through `SQLCraftFactory::session()` with an
extras driver key. They are **not affected** and require no changes.

---

## 7. Full File Change Summary

| File | Change type | Estimated effort |
|---|---|---|
| `src/Enums/DatabaseDriver.php` | **NEW** | Low |
| `src/ValueObjects/ConnectionParameters.php` | Add 1 property + import | Low |
| `src/Driver/DriverRegistry.php` | Add 1 method + import | Low |
| `src/SQLCraftFactory.php` | Replace 2 lines + add guard + import | Low |
| `src/Exceptions/DriverNotFoundException.php` | Widen 1 type | Trivial |
| `src/Exceptions/DriverMisconfiguredException.php` | Widen 1 type | Trivial |
| `tests/Unit/Enums/DatabaseDriverTest.php` | **NEW** | Low |
| `tests/Unit/ValueObjects/NamedAndConnectionValueObjectsTest.php` | 3–4 line additions | Low |
| `tests/Unit/Driver/DriverRegistryTest.php` | 1 new test method | Low |
| `tests/Unit/SQLCraftFactoryTest.php` | 1 new test method | Low |
| `docs/user-guide/connections.md` | ~15 example replacements | Medium |

---

## 8. Suggested Implementation Order

1. Create `src/Enums/DatabaseDriver.php` — no dependencies.
2. Update `src/ValueObjects/ConnectionParameters.php` — depends on step 1.
3. Update `src/Driver/DriverRegistry.php` — depends on step 1.
4. Update `src/SQLCraftFactory.php` — depends on steps 1–3.
5. Update both exception classes — independent, can be done at any point.
6. Write/update all tests — after steps 1–5 so they compile and pass.
7. Update `docs/user-guide/connections.md` — last, after tests pass.

Steps 1–5 form a single atomic unit and can land in one commit. Tests and docs
can follow as separate commits, or all together.

---

## 9. Open Questions

1. **Custom driver support.** A caller who has registered a custom driver (not
   in the enum) must currently fall back to `DriverRegistry::get(string $name)`
   directly, or register it under one of the enum's backing values. Consider
   whether a future `DatabaseDriver::custom(string $value)` factory approach is
   needed, or whether a separate `withCustomDriver(string $driverName)` path on
   the factory is cleaner. Out of scope for this change.

2. **`DriverNotFoundException::$driver` type.** The widening to
   `string|DatabaseDriver` was chosen for minimal blast radius. If the project
   prefers a clean type, consider tightening to `DatabaseDriver` only and
   updating all internal callers to pass enum values. That is a larger refactor
   and should be a separate ticket.

3. **Symfony YAML config.** The Symfony integration doc cannot use the PHP enum
   syntax in YAML. A factory-service pattern is the idiomatic solution but
   requires a real example; consider whether a `DatabaseDriver::from()` call in
   a tagged-factory service definition is worth adding to the guide.
