# Implementation Plan: First-Class `DatabaseDriver` Enum on `ConnectionParameters`

**Status:** Ready for implementation  
**Based on:** Deep blast-radius audit of the full codebase (July 2026)  
**PHP requirement:** `^8.4` — native enums are fully supported  
**Autoload:** PSR-4 `"SQLCraft\\" => "src/"` covers `src/Enums/` automatically  

---

## 1. Executive Summary

`ConnectionParameters` buries the database engine selector inside a free-form
array:

```php
// Current broken API — stringly typed, invisible to static analysis
new ConnectionParameters(
    host: 'localhost',
    database: 'shop',
    extras: ['driver' => 'mysql'],
)
```

`SQLCraftFactory::session()` extracts it with one unsafe line:

```php
// src/SQLCraftFactory.php:112 — the ONLY place in the codebase that reads this
$driverName = (string) ($parameters->extras['driver'] ?? 'sqlite');
```

This change promotes `driver` to a proper first-class typed property using a
PHP string-backed enum, `DatabaseDriver`.

---

## 2. Complete Blast Radius — Every Affected File

### 2.1 Files That MUST Change (16 files)

| # | File | Type | Reason |
|---|---|---|---|
| 1 | `src/Enums/DatabaseDriver.php` | **NEW** | The enum itself |
| 2 | `src/ValueObjects/ConnectionParameters.php` | modify | Add `?DatabaseDriver $driver = null` property |
| 3 | `src/Driver/DriverRegistry.php` | modify | Add typed `getByDriver()` convenience method |
| 4 | `src/SQLCraftFactory.php` | modify | Replace `extras['driver']` with `$parameters->driver`; add null guard |
| 5 | `src/Exceptions/DriverNotFoundException.php` | modify | Widen `$driver` to `string\|DatabaseDriver` |
| 6 | `src/Exceptions/DriverMisconfiguredException.php` | modify | Same widening |
| 7 | `tests/Unit/Enums/DatabaseDriverTest.php` | **NEW** | Enum coverage |
| 8 | `tests/Unit/ValueObjects/NamedAndConnectionValueObjectsTest.php` | modify | Add `driver` assertions |
| 9 | `tests/Unit/Driver/DriverRegistryTest.php` | modify | Test `getByDriver()` |
| 10 | `tests/Unit/SQLCraftFactoryTest.php` | **NEW** | Test null-driver guard in `session()` |
| 11 | `docs/user-guide/connections.md` | modify | 15 `extras: ['driver' => ...]` occurrences |
| 12 | `docs/advanced/framework-integration.md` | modify | 3 occurrences (lines 36, 160, 424) + Laminas config note (line 324) |
| 13 | `docs/api/overview.md` | modify | 1 occurrence (line 62) + parameters table |
| 14 | `docs/api/exceptions.md` | modify | 2 occurrences (lines 106, 134) |
| 15 | `docs/getting-started/quick-start.md` | modify | 3 occurrences (lines 69, 84, 99) |
| 16 | `docs/development/testing.md` | modify | 2 occurrences (lines 249, 274) |

### 2.2 Files That Should Gain One Test (optional but recommended)

| File | What to add |
|---|---|
| `tests/Unit/Exceptions/ExceptionHierarchyTest.php` | Assert enum values are stored in `DriverNotFoundException` and `DriverMisconfiguredException` |

### 2.3 Files Verified As NOT Needing Changes

These were individually inspected and are confirmed clean:

**Source:**
- `composer.json` — PSR-4 `"SQLCraft\\" => "src/"` covers `src/Enums/` with no changes
- `src/Exceptions/ConnectionException.php` — `$driver` here is a **redacted DSN string** (set from `SecretRedactor::dsn($dsn)` in `PdoConnectionFactory`), not a driver selector; do not touch
- `src/Events/ConnectionOpenedEvent.php` — `$driver` is the platform name from `$platform->getName()`
- `src/Events/ConnectionFailedEvent.php` — same
- `src/Events/ConnectionClosedEvent.php` — same
- `src/Events/ConnectionEventDispatcher.php` — same
- `src/Contracts/Events/ConnectionEventDispatcherInterface.php` — same
- `src/Connection/EnvCredentialProvider.php` — only resolves `Credential`; no `ConnectionParameters` construction
- `src/Connection/ArrayCredentialProvider.php` — same
- `src/Connection/CallbackCredentialProvider.php` — same
- `src/Connection/PdoConnectionFactory.php` — receives `ConnectionParameters` but reads only `username`, `password`, `host`, `database`; never inspects `extras`
- `src/Driver/MySQLDriver.php` — reads `host`, `port`, `socket`, `database`, `charset` only
- `src/Driver/PostgreSQLDriver.php` — same pattern
- `src/Driver/SqliteDriver.php` — same pattern
- `src/Driver/SqlServerDriver.php` — same pattern
- `src/Connection/ConnectionFactory.php` — thin wrapper; just calls `$driver->connect($parameters)`

**Tests (all use direct driver instantiation, bypass SQLCraftFactory):**
- `tests/Contract/MySQL/MySQLPlatformConformanceTest.php`
- `tests/Contract/PostgreSQL/PostgreSQLPlatformConformanceTest.php`
- `tests/Contract/SQLite/SqlitePlatformConformanceTest.php`
- `tests/Contract/SqlServer/SqlServerPlatformConformanceTest.php`
- `tests/Contract/MariaDB/MariaDbPlatformConformanceTest.php`
- `tests/Integration/ImportExport/ImportExportRoundTripTest.php`
- `tests/Integration/Schema/SchemaManagerEngineIntegrationTest.php`
- `tests/Integration/SqlServer/SqlServerIntegrationTest.php`
- `tests/Integration/Query/QueryEngineAcceptanceIntegrationTest.php`
- `tests/Unit/Driver/MySQLDriverTest.php`
- `tests/Unit/Driver/PostgreSQLDriverTest.php`
- `tests/Unit/Driver/SqliteDriverTest.php`
- `tests/Unit/Driver/SqlServerDriverTest.php`
- `tests/Unit/Connection/ConnectionFactoryTest.php`
- `tests/Unit/Connection/PdoConnectionFactoryTest.php`
- `tests/Unit/Connection/PdoExceptionTranslatorTest.php`
- `tests/Unit/Events/ConnectionLifecycleEventsTest.php`

**Examples (all use direct driver instantiation):**
- `examples/01-basic-connection/run.php` through `examples/08-multi-engine-comparison/run.php`

---

## 3. Design Decisions

### 3.1 Enum backing values must match existing registry keys exactly

`DriverRegistry::get(string $name)` looks up drivers by the string returned from
`DriverInterface::getName()`. The backing values below map 1-to-1:

| Enum case | Backing value | Source |
|---|---|---|
| `DatabaseDriver::MySQL` | `'mysql'` | `MySQLDriver::getName()` |
| `DatabaseDriver::MariaDB` | `'mariadb'` | alias registered in `SQLCraftFactory` |
| `DatabaseDriver::PostgreSQL` | `'pgsql'` | `PostgreSQLDriver::getName()` |
| `DatabaseDriver::SQLite` | `'sqlite'` | `SqliteDriver::getName()` |
| `DatabaseDriver::SqlServer` | `'sqlserver'` | `SqlServerDriver::getName()` |

Changing these values would require touching every driver's `getName()` — do not
do that; it is unnecessary blast radius.

### 3.2 The `driver` property is nullable

`ConnectionParameters` is used in two paths:

1. **Via `SQLCraftFactory::session()`** — must know which driver to route to.
2. **Direct driver instantiation** — e.g. `new MySQLDriver(...)->connect($params)`. The engine is already known at the call site; `driver` is irrelevant.

`?DatabaseDriver $driver = null` keeps the value object usable in both paths.
`SQLCraftFactory::session()` will throw `\InvalidArgumentException` when `driver`
is `null`, replacing the old silent `?? 'sqlite'` fallback.

### 3.3 `driver` is added as the last constructor parameter

All existing call sites in the codebase use **named arguments**. Adding `driver`
as the last parameter is backward-compatible: every existing `new
ConnectionParameters(host: ..., database: ...)` call compiles unchanged. Do not
reorder existing parameters.

### 3.4 `extras` is unchanged

`extras` continues to hold genuine driver-specific pass-through options (e.g.
`init_command`, `applicationName`, `options`, `encrypt`). Only the driver
selector is extracted. Do not remove the `extras` parameter or its `@param`
annotation.

### 3.5 Exception `$driver` types

`DriverNotFoundException::$driver` and `DriverMisconfiguredException::$driver`
are widened to `string|DatabaseDriver` so callers can pass either a typed enum
case or a raw string (for custom/external drivers not in the enum). Existing
tests that pass plain strings remain valid.

**Critical:** `ConnectionException::$driver` is completely different — it holds a
**redacted DSN string** (e.g. `sqlite::memory:`, `mysql:host=...`), not a driver
selector. Do **not** change `ConnectionException`.

---

## 4. Implementation Steps

Execute these steps in order. Do not skip any step.

---

### Step 1 — Create `src/Enums/DatabaseDriver.php`

Create the directory `src/Enums/` and write this file:

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

**Verification after this step:** The file must be readable by PHP with no syntax
errors: `php -l src/Enums/DatabaseDriver.php` must print `No syntax errors`.

---

### Step 2 — Modify `src/ValueObjects/ConnectionParameters.php`

**Current file (full):**

```php
<?php

declare(strict_types=1);

namespace SQLCraft\ValueObjects;

use InvalidArgumentException;
use SensitiveParameter;
use SQLCraft\Support\StringUtil;

final readonly class ConnectionParameters
{
    /**
     * @param array<string, scalar|null> $ssl
     * @param array<string, scalar|null> $extras
     */
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
    ) {
```

**Required changes — two edits:**

**Edit A — add import after `use SensitiveParameter;`:**

Find this exact line:
```
use SensitiveParameter;
```
Insert immediately after it:
```
use SQLCraft\Enums\DatabaseDriver;
```

**Edit B — add `driver` parameter to constructor (after `public array $extras = [],`):**

Find this exact text:
```php
        public array $extras = [],
    ) {
```
Replace with:
```php
        public array $extras = [],
        public ?DatabaseDriver $driver = null,
    ) {
```

**Final constructor signature must look like this:**

```php
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
        public ?DatabaseDriver $driver = null,
    ) {
```

The body of the constructor (the validation code) does **not** change.

**Verification:** `php -l src/ValueObjects/ConnectionParameters.php` — no errors.

---

### Step 3 — Modify `src/Driver/DriverRegistry.php`

**Edit A — add import at the top, after the existing `use` statement:**

Find:
```php
use SQLCraft\Exceptions\DriverNotFoundException;
```
Insert immediately before it:
```php
use SQLCraft\Enums\DatabaseDriver;
```

**Edit B — add `getByDriver()` method after the existing `get()` method:**

Find this exact block:
```php
    public function get(string $name): DriverInterface
    {
        return $this->drivers[$name]
            ?? throw new DriverNotFoundException(sprintf('Driver not found: %s.', $name), $name);
    }

    /** @return list<string> */
```
Replace with:
```php
    public function get(string $name): DriverInterface
    {
        return $this->drivers[$name]
            ?? throw new DriverNotFoundException(sprintf('Driver not found: %s.', $name), $name);
    }

    public function getByDriver(DatabaseDriver $driver): DriverInterface
    {
        return $this->get($driver->value);
    }

    /** @return list<string> */
```

**Verification:** `php -l src/Driver/DriverRegistry.php` — no errors.

---

### Step 4 — Modify `src/SQLCraftFactory.php`

**Edit A — add import.** In the existing block of `use` statements, add:
```php
use SQLCraft\Enums\DatabaseDriver;
```
Place it alphabetically among the existing `use` lines (after
`use SQLCraft\DDL\DdlManager;`, before `use SQLCraft\Events\...`).

**Edit B — forward `driver` in the credential-copy block.**

Find this exact block (lines 99–110):
```php
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
            );
```
Replace with:
```php
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
                driver: $parameters->driver,
            );
```

**Edit C — replace the driver lookup (lines 112–114).**

Find this exact block:
```php
        $driverName = (string) ($parameters->extras['driver'] ?? 'sqlite');
        $connection = $this->drivers->get($driverName)->connect($parameters);
        $connectionName = $name ?? $connection->getName() ?? $driverName;
```
Replace with:
```php
        if ($parameters->driver === null) {
            throw new \InvalidArgumentException(
                'ConnectionParameters::$driver must be set when using SQLCraftFactory::session(). '
                . 'Pass a DatabaseDriver enum case, e.g. driver: DatabaseDriver::SQLite.'
            );
        }
        $connection = $this->drivers->getByDriver($parameters->driver)->connect($parameters);
        $connectionName = $name ?? $connection->getName() ?? $parameters->driver->value;
```

**Verification:** `php -l src/SQLCraftFactory.php` — no errors.

---

### Step 5 — Modify `src/Exceptions/DriverNotFoundException.php`

**Edit A — add import before the class declaration:**

Find:
```php
final class DriverNotFoundException extends DriverException
```
Insert immediately before it:
```php
use SQLCraft\Enums\DatabaseDriver;

```

**Edit B — widen the `$driver` property type:**

Find:
```php
        public readonly string $driver = '',
```
Replace with:
```php
        public readonly string|DatabaseDriver $driver = '',
```

**Verification:** `php -l src/Exceptions/DriverNotFoundException.php` — no errors.

---

### Step 6 — Modify `src/Exceptions/DriverMisconfiguredException.php`

Apply the **identical two edits** as Step 5 above:

**Edit A** — add `use SQLCraft\Enums\DatabaseDriver;` before the class.

**Edit B** — change `public readonly string $driver = ''` to
`public readonly string|DatabaseDriver $driver = ''`.

**Verification:** `php -l src/Exceptions/DriverMisconfiguredException.php` — no errors.

---

### Step 7 — Run existing tests (smoke-check)

Before touching any test files, verify the source changes compile and existing
tests still pass:

```bash
./vendor/bin/phpunit --testsuite=unit
```

All existing unit tests must pass. If any fail, fix the source changes before
proceeding.

---

### Step 8 — Create `tests/Unit/Enums/DatabaseDriverTest.php`

Create the directory `tests/Unit/Enums/` and write:

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
        self::assertNull(DatabaseDriver::tryFrom('MySQL')); // case-sensitive
    }

    public function testCasesMethodReturnsFiveEntries(): void
    {
        self::assertCount(5, DatabaseDriver::cases());
    }
}
```

---

### Step 9 — Modify `tests/Unit/ValueObjects/NamedAndConnectionValueObjectsTest.php`

This file is at `tests/Unit/ValueObjects/NamedAndConnectionValueObjectsTest.php`.

**Edit A — add import after the existing `use` block.** The existing imports end
at line ~16. Add:
```php
use SQLCraft\Enums\DatabaseDriver;
```

**Edit B — extend `testConnectionParametersStoresConnectionOptions`.**

Find the `$parameters = new ConnectionParameters(` block inside that test method.
The current constructor call ends with:
```php
            extras: ['applicationName' => 'sqlcraft'],
        );
```
Change it to:
```php
            extras: ['applicationName' => 'sqlcraft'],
            driver: DatabaseDriver::MySQL,
        );
```

Then, after the existing assertions at the end of that test, add:
```php
        self::assertSame(DatabaseDriver::MySQL, $parameters->driver);
```

**Edit C — add a new test method** after
`testConnectionParametersStoresConnectionOptions`:

```php
    public function testConnectionParametersDefaultsDriverToNull(): void
    {
        $parameters = new ConnectionParameters(database: 'shop');

        self::assertNull($parameters->driver);
    }
```

---

### Step 10 — Modify `tests/Unit/Driver/DriverRegistryTest.php`

**Edit A — add import:**
```php
use SQLCraft\Enums\DatabaseDriver;
```

**Edit B — add a new test method** after `testItThrowsForAnUnknownDriver`:

```php
    public function testGetByDriverDelegatesToGetUsingBackingValue(): void
    {
        $driver = $this->fakeDriver(); // getName() returns 'fake'
        $registry = new DriverRegistry();
        // Register the fake driver under the 'sqlite' key so we can look it up via the enum
        $registry->registerAlias('sqlite', $driver);

        self::assertSame($driver, $registry->getByDriver(DatabaseDriver::SQLite));
    }
```

---

### Step 11 — Update `docs/user-guide/connections.md`

There are **15 occurrences** of `extras: ['driver' => ...]` in this file. Each
must be replaced. The transformation rules are:

**Rule A — extras array containing ONLY `driver`:**
```php
// Before
extras: ['driver' => 'mysql']

// After
driver: DatabaseDriver::MySQL,
```

**Rule B — extras array containing `driver` PLUS other keys:**
```php
// Before
extras: [
    'driver' => 'mysql',
    'init_command' => 'SET sql_mode="STRICT_ALL_TABLES"'
]

// After
driver: DatabaseDriver::MySQL,
extras: ['init_command' => 'SET sql_mode="STRICT_ALL_TABLES"']
```

**Driver string → enum case mapping:**

| Old string value | New enum case |
|---|---|
| `'mysql'` | `DatabaseDriver::MySQL` |
| `'mariadb'` | `DatabaseDriver::MariaDB` |
| `'pgsql'` | `DatabaseDriver::PostgreSQL` |
| `'sqlite'` | `DatabaseDriver::SQLite` |
| `'sqlserver'` | `DatabaseDriver::SqlServer` |

**Rule C — dynamic driver from environment variable:**

Old pattern:
```php
extras: ['driver' => $_ENV['DB_DRIVER'] ?? 'mysql']
```
New pattern:
```php
driver: DatabaseDriver::from($_ENV['DB_DRIVER'] ?? 'mysql'),
```

**Rule D — add `use` statement to every code block that uses `DatabaseDriver`:**

Every PHP code block in the docs that references `DatabaseDriver::*` must have
`use SQLCraft\Enums\DatabaseDriver;` at the top of the example.

**Update the Connection Parameters reference table.** Find:

```md
| `extras` | `array` | Driver-specific options | `[]` |
```

Replace with:

```md
| `driver` | `?DatabaseDriver` | Database engine (required by `SQLCraftFactory`) | `null` |
| `extras` | `array` | Driver-specific pass-through options | `[]` |
```

**Update the "Driver Selection" subsection.** Replace the entire block that
explains `extras: ['driver' => '...']` with:

```md
### Driver Selection

Pass a `DatabaseDriver` enum case as the `driver` parameter:

```php
use SQLCraft\Enums\DatabaseDriver;
use SQLCraft\ValueObjects\ConnectionParameters;

$params = new ConnectionParameters(
    database: 'mydb',
    driver: DatabaseDriver::MySQL,
);
```

Available cases and their corresponding engines:

| Enum case | Engine |
|---|---|
| `DatabaseDriver::MySQL` | MySQL |
| `DatabaseDriver::MariaDB` | MariaDB (uses MySQL driver internally) |
| `DatabaseDriver::PostgreSQL` | PostgreSQL |
| `DatabaseDriver::SQLite` | SQLite |
| `DatabaseDriver::SqlServer` | Microsoft SQL Server |

The `driver` parameter is required when calling `SQLCraftFactory::session()`.
It is optional (defaults to `null`) when constructing `ConnectionParameters` for
direct driver use, e.g. `new MySQLDriver(...)->connect($params)`.
```

**Update the Symfony YAML integration example.** Find the YAML snippet that
shows `extras: { driver: '%env(DB_DRIVER)%' }`. Replace with:

```md
> **Symfony YAML note:** The `driver` parameter requires a `DatabaseDriver` enum
> case, which cannot be expressed directly in YAML. Resolve it in a factory
> service or compiler pass using `DatabaseDriver::from($driverString)`.
```

---

### Step 12 — Create `tests/Unit/SQLCraftFactoryTest.php`

This file does not yet exist (`tests/Unit/Connection/ConnectionFactoryTest.php` covers
the lower-level `ConnectionFactory` wrapper, not `SQLCraftFactory`). Create it:

```php
<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SQLCraft\Enums\DatabaseDriver;
use SQLCraft\SQLCraftFactory;
use SQLCraft\ValueObjects\ConnectionParameters;

final class SQLCraftFactoryTest extends TestCase
{
    public function testSessionThrowsWhenDriverIsNull(): void
    {
        $factory = new SQLCraftFactory();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('DatabaseDriver enum case');

        $factory->session(new ConnectionParameters(database: ':memory:'));
    }
}
```

**Verification:** `./vendor/bin/phpunit tests/Unit/SQLCraftFactoryTest.php` — one
test, passes (exception is thrown as expected).

---

### Step 13 — Update `docs/advanced/framework-integration.md`

Three `extras: ['driver' => ...]` constructor call sites and one plain config
array that feeds into one of them.

**Site 1 — line 36 (simple single-key extras):**

Find:
```php
    extras: ['driver' => 'pgsql'],
```
Replace with:
```php
    driver: DatabaseDriver::PostgreSQL,
```
Add `use SQLCraft\Enums\DatabaseDriver;` to the `use` block at the top of that
code example.

**Site 2 — line 160 (dynamic driver from Laravel config array):**

Find:
```php
                    extras:   ['driver' => $config['driver']],
```
Replace with:
```php
                    driver:   DatabaseDriver::from($config['driver']),
```
Add `use SQLCraft\Enums\DatabaseDriver;` to that code block's imports.

**Site 3 — line 424 (simple single-key extras in tenant factory):**

Find:
```php
                extras:   ['driver' => 'pgsql'],
```
Replace with:
```php
                driver:   DatabaseDriver::PostgreSQL,
```
Add `use SQLCraft\Enums\DatabaseDriver;` to that code block's imports.

**Site 4 — line 324 (Laminas plain config array — different treatment):**

```php
'sqlcraft' => [
    'driver'   => 'pgsql',
    'host'     => '127.0.0.1',
    'database' => 'myapp',
],
```

This is a **PHP config array**, not a `ConnectionParameters` constructor call.
The string value `'pgsql'` is correct as-is — it will be passed through
`DatabaseDriver::from($config['driver'])` by the service factory (see Site 2
pattern above). Do **not** change this to `DatabaseDriver::PostgreSQL` — it is
not inside a `new ConnectionParameters(...)` call. The config array stays
unchanged.

---

### Step 14 — Update `docs/api/overview.md`

**Site 1 — line 62:**

Find:
```php
        extras: ['driver' => 'pgsql'],
```
Replace with:
```php
        driver: DatabaseDriver::PostgreSQL,
```
Add `use SQLCraft\Enums\DatabaseDriver;` to that code block's imports.

**Site 2 — parameters table (around line 243):**

Find the `ConnectionParameters` reference row that mentions "driver" in the
description column. Add a dedicated `driver` row:

```md
| `driver` | `?DatabaseDriver` | Database engine (required by `SQLCraftFactory`) | `null` |
```

---

### Step 15 — Update `docs/api/exceptions.md`

Two identical simple replacements.

**Site 1 — line 106:**

Find:
```php
        extras: ['driver' => 'pgsql'],
```
Replace with:
```php
        driver: DatabaseDriver::PostgreSQL,
```

**Site 2 — line 134:** Same find/replace as Site 1.

Add `use SQLCraft\Enums\DatabaseDriver;` to both affected code blocks.

---

### Step 16 — Update `docs/getting-started/quick-start.md`

Three simple replacements, one per engine.

**Line 69:**
```diff
-        extras: ['driver' => 'mysql']
+        driver: DatabaseDriver::MySQL,
```

**Line 84:**
```diff
-        extras: ['driver' => 'pgsql']
+        driver: DatabaseDriver::PostgreSQL,
```

**Line 99:**
```diff
-        extras: ['driver' => 'sqlserver']
+        driver: DatabaseDriver::SqlServer,
```

Add `use SQLCraft\Enums\DatabaseDriver;` to each affected code block.

---

### Step 17 — Update `docs/development/testing.md`

Two simple replacements.

**Line 249:**
```diff
-            extras:   ['driver' => 'pgsql'],
+            driver:   DatabaseDriver::PostgreSQL,
```

**Line 274:**
```diff
-            extras:   ['driver' => 'sqlite'],
+            driver:   DatabaseDriver::SQLite,
```

Add `use SQLCraft\Enums\DatabaseDriver;` to each affected code block.

---

### Step 18 (optional) — Strengthen `tests/Unit/Exceptions/ExceptionHierarchyTest.php`

Add one new test method to the existing `ExceptionHierarchyTest` class to
confirm enum values round-trip through the exception:

```php
    use SQLCraft\Enums\DatabaseDriver; // add to imports

    public function testDriverExceptionsAcceptEnumCase(): void
    {
        $notFound      = new DriverNotFoundException('not found', DatabaseDriver::MySQL);
        $misconfigured = new DriverMisconfiguredException('bad config', DatabaseDriver::PostgreSQL);

        self::assertSame(DatabaseDriver::MySQL,      $notFound->driver);
        self::assertSame(DatabaseDriver::PostgreSQL, $misconfigured->driver);
    }
```

Note: the existing `testOtherTypedPayloadsAreExposed` test (which passes plain
strings `'mysql'` and `'pgsql'`) continues to pass unchanged because the
property type is `string|DatabaseDriver`.

---

## 5. Verification Checklist

Run after all changes are complete:

```bash
# 1. Syntax-check every changed PHP file
php -l src/Enums/DatabaseDriver.php
php -l src/ValueObjects/ConnectionParameters.php
php -l src/Driver/DriverRegistry.php
php -l src/SQLCraftFactory.php
php -l src/Exceptions/DriverNotFoundException.php
php -l src/Exceptions/DriverMisconfiguredException.php

# 2. Run full unit test suite (all tests must pass)
./vendor/bin/phpunit --testsuite=unit

# 3. Confirm no remaining extras+'driver' pattern in PHP source files
grep -rn "extras\['driver'\]" src/ tests/ --include="*.php"
# Expected output: (empty — nothing should remain)

# 4. Confirm no remaining extras+'driver' pattern in docs
grep -rn "extras.*'driver'" docs/ --include="*.md"
# Expected output: (empty — nothing should remain)

# 5. Confirm the new enum is autoloaded (no composer.json changes needed)
php -r "require 'vendor/autoload.php'; echo SQLCraft\Enums\DatabaseDriver::MySQL->value . PHP_EOL;"
# Expected output: mysql

# 6. Confirm all five enum backing values are correct
php -r "
require 'vendor/autoload.php';
use SQLCraft\Enums\DatabaseDriver;
foreach (DatabaseDriver::cases() as \$case) {
    echo \$case->name . ' => ' . \$case->value . PHP_EOL;
}
"
# Expected output (order may vary):
# MySQL => mysql
# MariaDB => mariadb
# PostgreSQL => pgsql
# SQLite => sqlite
# SqlServer => sqlserver
```

---

## 6. Common Mistakes to Avoid

1. **Do not change `ConnectionException::$driver`.** It holds a redacted DSN
   string (e.g. `mysql:host=localhost`) set inside `PdoConnectionFactory`. It
   is unrelated to the driver selector enum.

2. **Do not change any event class.** `ConnectionOpenedEvent::$driver`,
   `ConnectionFailedEvent::$driver`, and `ConnectionClosedEvent::$driver` all
   hold the platform name string from `$platform->getName()` — not the driver
   selector. Leave them as `string`.

3. **Do not add a `driver` parameter to `PdoConnectionFactory::connect()`.** It
   already receives a `PlatformInterface` which encapsulates the platform;
   no change is needed.

4. **Do not remove the `extras` parameter.** It still carries legitimate
   engine-specific options. Only the `driver` key is no longer read from it.

5. **Do not add a new case to `DatabaseDriver` without also registering an alias
   in `DriverRegistry`.** A case with no corresponding registry entry will throw
   `DriverNotFoundException` at runtime.

6. **Do not reorder existing `ConnectionParameters` constructor parameters.**
   The new `driver` parameter must go last. All existing call sites use named
   arguments, so order does not matter for them, but consistency demands last.

7. **Do not forget the `use` import** in every file that references
   `DatabaseDriver`. PHP will throw a fatal error if the class cannot be
   resolved.
