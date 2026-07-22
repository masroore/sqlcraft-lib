# 20 — Testing Strategy

> **Status:** Design draft
> **Scope:** Test pyramid, unit/integration/contract/golden/mutation/property-based testing, static analysis as a gate, streaming/transaction test technique, fixtures, CI matrix, coverage targets, contract-test authoring guide
> **Depends on terminology from:** `05-domain-model.md` (VOs/DTOs/exceptions), `07-module-breakdown.md` (module boundaries/testing seams), `08-driver-architecture.md` (PlatformInterface, segregated sub-interfaces, DriverRegistry), `09-capability-model.md` (Capability/CapabilitySet), `19-package-structure.md` (`tests/` layout, `composer.json` scripts)

---

## 1. Why This Document Exists: Inverting Adminer's Approach

Adminer (`03-adminer-analysis.md`) ships with **no unit tests**. Its `tests/` directory holds ~10-minute Selenium-style end-to-end browser tests that drive the rendered UI and assert on HTML output. This is a defensible choice *for Adminer specifically*: it is a single-file, UI-first tool where the thing worth testing is "does clicking this link produce the right page," and its lack of internal seams (global `$driver`, free functions, HTML-interspersed logic — 06 §5) makes true unit testing nearly impossible without a rewrite.

SQLCraft inverts this completely, and can, precisely because `06-package-architecture.md`'s hexagonal boundary and `07-module-breakdown.md`'s per-module interfaces exist:

| | Adminer | SQLCraft |
|---|---|---|
| Primary test type | End-to-end browser (Selenium-style) | Unit (fast, no I/O) |
| Speed of primary suite | ~10 minutes | Sub-second per test, whole unit suite in seconds |
| What is asserted | Rendered HTML strings | Typed return values (VOs/DTOs/Collections) |
| DB required to test core logic | Yes, always | No — only for Integration/Contract tiers |
| Isolation of engine differences | None (single active driver, manual QA per engine) | `PlatformInterface` conformance suite (§4) run identically per engine |
| Regression safety net for generated SQL | None (manual inspection) | Golden/snapshot fixtures (§5) |
| CI cost | High (browser automation), rarely run per-commit | Low for unit+static (every push), higher tier (integration) on a matrix, scheduled/gated |

This is not merely "SQLCraft also has tests" — it is a structural claim: **because application services depend only on interfaces (06 §4), the overwhelming majority of SQLCraft's logic (SQL generation, quoting, capability resolution, DTO hydration, query building) is testable with zero database connection at all.** The DB is only required to validate that the *adapters* (Driver/Platform/Connection concretes) correctly implement what the interfaces promise — which is exactly what the contract-test tier (§4) exists to isolate.

---

## 2. The Test Pyramid

```
                    ▲  slowest, fewest
                   ╱ ╲
                  ╱ E2E╲              (none — no UI exists to end-to-end test; see §1)
                 ╱───────╲
                ╱ Integr. ╲           Testcontainers, real engines, per-version matrix (§3)
               ╱───────────╲
              ╱  Contract    ╲        PlatformInterface conformance suite, every driver (§4)
             ╱─────────────────╲
            ╱   Golden/Snapshot  ╲    Generated-SQL fixtures per platform, no DB (§5)
           ╱───────────────────────╲
          ╱          Unit            ╲  VOs, DTOs, quoting, capability resolution — the bulk (§2.1)
         ╱───────────────────────────────╲
        ▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔  fastest, most numerous
```

There is deliberately **no end-to-end tier** — SQLCraft has no UI, no HTTP surface, no rendering (00 §hard-constraints), so there is nothing an E2E browser test could exercise that isn't better exercised at the Integration tier against the real public API (`18-public-api.md` §3's workflows are, not coincidentally, exactly what the Integration suite exercises against real engines).

### 2.1 Unit Tests — the bulk of the suite

**What belongs here (no DB connection, no I/O, sub-millisecond per test):**

- Every VO/DTO constructor validation and `equals()`/`clone with` behavior (05 §3-4).
- Every `Collection` class's `filter()`/`map()`/`sort()`/lazy-materialization behavior (05 §6, 07 §4).
- Every platform's SQL-generation methods (`quoteIdentifier()`, `applyPagination()`, `renderColumnDefinition()`, etc. — 08 §3) tested by direct instantiation, no connection needed, per 08 §7's testing seam ("Instantiate a concrete platform directly").
- `PlatformCapabilityResolver`/`buildCapabilityMatrix()` version-predicate logic (09 §4) — pure function of a `ServerVersion` input, no DB.
- `MetadataFactory` row-hydration logic (05 §8) — fed fixture arrays representing raw PDO rows, asserted against expected typed DTOs. This is the single highest-value unit-test target for driver contributors: it is where "the SQL and the hydration agree" bugs are cheapest to catch.
- `QueryBuilder`/`DdlBuilder` fluent construction, asserting on `->toSql()` string output *only for the parts that don't require golden fixtures* — trivial builder assembly (`->where()->orderBy()` chaining producing the right method-call sequence) is a unit test; full multi-clause generated SQL correctness per platform is promoted to the Golden tier (§5) because that is where regressions are most likely and most valuable to pin exactly.
- Exception construction and typed-property carriage (05 §9) — e.g. `CapabilityNotSupportedException::for(...)` carries the right `Capability`/platform/version.

**Mocking:** `ConnectionInterface` is the one seam mocked pervasively across Unit tests for services that *do* need a connection call (e.g., `MetadataService` orchestration logic, 07 §8's testing seam: "Mock ConnectionInterface to return fixture rows; test hydration logic"). PHPUnit's built-in test doubles are sufficient; no additional mocking library is required given `ConnectionInterface`'s narrow surface (07 §5's interface sketch).

```php
namespace SQLCraft\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use SQLCraft\Metadata\MySQLMetadataFactory;
use SQLCraft\DTO\ColumnMeta;

final class MySQLMetadataFactoryTest extends TestCase
{
    public function testHydratesAutoIncrementColumn(): void
    {
        $factory = new MySQLMetadataFactory();

        $column = $factory->createColumnMeta([
            'field' => 'id', 'type' => 'int', 'length' => 11, 'unsigned' => true,
            'null' => false, 'auto_increment' => true, 'default' => null,
        ]);

        self::assertInstanceOf(ColumnMeta::class, $column);
        self::assertTrue($column->autoIncrement);
        self::assertTrue($column->dataType->unsigned);
    }
}
```

**Target:** Unit tests are the majority of the suite by test count (target: 70-80% of total test count) and should run, in full, in under 15 seconds on a contributor's laptop — this speed is a hard requirement because it is what makes running `composer test` on every save practical during development, which is the entire point of front-loading the pyramid this way.

---

## 3. Integration Tests

**What belongs here:** anything that can only be validated against a real, running engine — actual query execution, actual transaction/deadlock behavior, actual `INFORMATION_SCHEMA`/`pg_catalog`/`sys.*` introspection SQL returning real rows, actual streaming cursor behavior under real network I/O.

**Infrastructure: Testcontainers.** Each of MySQL, MariaDB, PostgreSQL, MSSQL, and Oracle is started as an ephemeral Docker container per test-class (or per-suite, with transaction-rollback-based test isolation *within* a shared container to avoid the cost of one container per test method — see below). **SQLite requires no container** — `:memory:` databases are used directly, making SQLite integration tests nearly as fast as unit tests and the cheapest tier to run on every PR.

```php
namespace SQLCraft\Tests\Integration\PostgreSQL;

use PHPUnit\Framework\Attributes\Group;
use SQLCraft\Tests\Integration\AbstractEngineTestCase;

#[Group('integration')]
#[Group('pgsql')]
final class SchemaManagerIntegrationTest extends AbstractEngineTestCase
{
    protected static function engine(): string { return 'pgsql'; }

    public function testDescribeTableReturnsFullStructure(): void
    {
        $db = $this->session(); // DatabaseSession against the live Testcontainers instance
        $db->ddl()->execute($this->fixtureSchema()->createOrdersTable());

        $structure = $db->schema()->describeTable('testdb', 'orders');

        self::assertCount(4, $structure->columns);
        self::assertTrue($structure->columns->get('id')->primary);
    }
}
```

**Test isolation strategy — transaction rollback, not container-per-test:** a container is started once per test *class* (grouped by engine+version) via a PHPUnit `#[BeforeClass]`-equivalent hook; each individual test method runs inside a transaction that is rolled back in `tearDown()`, so tests do not interfere with each other's data without paying container-startup cost per method. DDL tests that cannot be transactional on a given engine (some DDL auto-commits, e.g., MySQL) instead run against a per-test-method freshly-created schema/database name (`test_{uuid}`) that is dropped in `tearDown()`.

**Why not always use containers even for SQLite:** SQLite's `:memory:` mode makes it the fastest tier and there is no engine-specific network/auth surface worth testing against a real file-backed SQLite — the meaningful integration coverage (real SQL execution, real type coercion) is fully present in `:memory:` mode.

**When Integration tests run:** on every pull request for SQLite (cheap); on a scheduled + on-demand matrix (§8) for the containerized engines, to avoid every contributor's PR waiting on a 5-engine Docker matrix for a docs typo fix. Merges to `main` always run the full matrix as a gate.

---

## 4. Driver-Compatibility (Contract) Tests

This is the tier that makes the project's central extensibility claim — "adding a driver requires only implementing interfaces" (08 §1) — actually *true* rather than aspirational. Without an explicit conformance suite, a new `PlatformInterface` implementation could compile, pass PHPStan, and still silently violate a behavioral expectation no interface signature can express (e.g., "quoted identifiers must round-trip through `quoteIdentifier()`+parsing," or "`applyPagination()` must never return more than `limit` rows even when the underlying table has fewer matching an OFFSET past the end").

**Design: one shared abstract test suite, run once per registered driver.**

```php
namespace SQLCraft\Tests\Contract;

/**
 * Every PlatformInterface implementation MUST pass this suite.
 * Concrete engine test classes extend this and supply a live connection + platform.
 * This is the authoritative definition of "conformant platform" — more authoritative
 * than the interface signatures alone, because it encodes *behavior*, not just *shape*.
 */
abstract class PlatformConformanceTestCase extends AbstractEngineTestCase
{
    public function testQuoteIdentifierRoundTrips(): void
    {
        $platform = $this->platform();
        $id = new Identifier('weird "table" name');
        $quoted = $platform->quoteIdentifier($id);

        // The quoted form, embedded in a real SQL statement, must be accepted by the live engine.
        $this->connection()->execute("SELECT 1 AS {$quoted}");
        $this->addToAssertionCount(1); // no exception thrown = pass
    }

    public function testApplyPaginationNeverExceedsLimit(): void
    {
        $sql = $this->platform()->applyPagination('SELECT * FROM contract_fixture_rows', limit: 5, offset: 0);
        $rows = $this->connection()->execute($sql)->rows();
        self::assertLessThanOrEqual(5, count(iterator_to_array($rows)));
    }

    public function testDescribeTableReturnsAllColumnsInDeclaredOrder(): void
    {
        // Runs the same CREATE TABLE (via DdlDialectInterface) then asserts column order
        // from IntrospectionDialectInterface matches declaration order exactly.
        // ...
    }

    public function testCapabilityMatrixMatchesLiveServerBehavior(): void
    {
        // For every Capability the resolved CapabilitySet claims to support, actually
        // attempt the corresponding minimal operation and assert it does NOT throw.
        // For every Capability NOT claimed, assert the corresponding operation DOES throw
        // CapabilityNotSupportedException when guarded, or is simply not exercised if there
        // is no safe way to attempt an unsupported DDL feature without side effects.
    }

    // ... full suite continues: FK action round-trips, transaction isolation guarantees,
    // NULL vs empty-string default value discrimination (05 §3.2's DefaultValue VO),
    // streaming cursor exhaustion behavior, prepared-statement rebind behavior, etc.
}
```

```php
namespace SQLCraft\Tests\Contract\MySQL;

final class MySQLPlatformConformanceTest extends PlatformConformanceTestCase
{
    protected static function engine(): string { return 'mysql'; }
}

namespace SQLCraft\Tests\Contract\PostgreSQL;

final class PostgreSQLPlatformConformanceTest extends PlatformConformanceTestCase
{
    protected static function engine(): string { return 'pgsql'; }
}
// ... one thin subclass per built-in engine, plus MariaDB and CockroachDB as flavor-subclass
// conformance runs (08 §6) sharing the *same* base suite as their parent platform.
```

**Contract-test authoring guide for driver contributors (e.g., someone implementing the DuckDB driver from 08 §9):**

1. Implement `DriverInterface` + the segregated `PlatformInterface` sub-interfaces your engine actually supports (08 §3). You are not required to implement `DdlDialectInterface` fully if your engine has no DDL concept — the conformance suite skips assertions gated behind a `Capability` your `buildCapabilityMatrix()` does not claim.
2. Create `tests/Contract/DuckDb/DuckDbPlatformConformanceTest extends PlatformConformanceTestCase`, override `engine()` and any container/connection bootstrapping needed for your engine.
3. Run `composer test:contract -- --filter=DuckDb`. Every failure is either (a) a genuine platform bug, or (b) a legitimate behavioral difference the base suite's assertion was too strict about — in case (b), the fix is a `#[RequiresCapability(...)]` PHPUnit attribute on the specific base-suite test method, gating it behind a capability check, **not** an engine-specific override that weakens the shared assertion for everyone.
4. A passing `PlatformConformanceTestCase` run is the acceptance bar for merging a new built-in driver, and the documented bar third-party drivers can self-certify against without needing SQLCraft maintainer review of their internal implementation.

**This suite is the single most important test asset in the project** — everything else validates that SQLCraft's own code is correct; this suite validates that the *extensibility promise* (08, 18 §9) is real.

---

## 5. Golden / Snapshot Tests for Generated SQL

Every platform's DDL/Query generation (08 §11, 07 §9) is checked against **checked-in fixture SQL strings**, one fixture file per (platform × generated-statement-shape) pair. This is the tier that catches "the generated SQL changed and nobody meant it to."

```
tests/Golden/fixtures/
├── mysql/
│   ├── create_table_simple.sql
│   ├── create_table_with_fk_and_check.sql
│   ├── alter_table_add_column.sql
│   └── select_with_pagination.sql
├── pgsql/
│   ├── create_table_simple.sql
│   └── ...
└── sqlserver/
    └── select_with_pagination.sql     # OFFSET...FETCH shape, distinct from MySQL's LIMIT/OFFSET
```

```php
namespace SQLCraft\Tests\Golden;

final class DdlGoldenTest extends TestCase
{
    /** @dataProvider platformProvider */
    public function testCreateTableSimple(string $platformClass, string $fixtureFile): void
    {
        $platform = new $platformClass();
        $ddl = (new DdlBuilder($platform))->createTable(/* ... shared fixture VO input ... */);

        $expected = file_get_contents(__DIR__ . "/fixtures/{$fixtureFile}");
        self::assertSame(trim($expected), trim($ddl->toSql()));
    }
}
```

**Regeneration workflow:** a `composer golden:update` script (using `--update-snapshots`-style PHPUnit tooling, or a small custom regenerator script in `tools/`) rewrites fixture files from current output. **This script is never run automatically in CI** — a diff to a golden fixture is *always* a deliberate, reviewed change in the PR (the fixture file's diff itself is the reviewer-visible artifact showing exactly what SQL text changed and for which platform), never a silent auto-update.

**Why this tier is separate from ordinary Unit assertions:** golden fixtures make large, multi-clause SQL string diffs reviewable as *text diffs in the PR* rather than as assertions buried in test code — a reviewer sees `- ENGINE=InnoDB\n+ ENGINE=InnoDB DEFAULT CHARSET=utf8mb4` directly in the file diff, which is far more legible than diffing two long PHP string literals inside an assertion call.

---

## 6. Mutation Testing (Infection)

**Target: minimum mutation score indicator (MSI) of 80%, minimum covered-code MSI of 90%**, enforced via the `composer infection` script (19 §3):

```json
"infection": "infection --min-msi=80 --min-covered-msi=90"
```

```json
// infection.json.dist (sketch)
{
    "source": { "directories": ["src"] },
    "logs": { "text": "infection.log", "summary": "infection-summary.log" },
    "mutators": {
        "@default": true,
        "TrueValue": { "ignore": ["src/Capabilities/CapabilitySet.php::has"] }
    },
    "minMsi": 80,
    "minCoveredMsi": 90
}
```

**Why mutation testing matters specifically for this codebase:** SQLCraft's correctness bar is largely about *exact string generation* (SQL dialects) and *exact boolean gating* (capability checks) — both are exactly the kind of logic where line/branch coverage can be 100% while a mutated conditional (`>=` flipped to `>`) or a mutated string literal still passes every existing assertion, because the assertions only checked "no exception" rather than "the precise expected value." Mutation testing is the mechanism that forces test authors toward the precise-value assertions the Golden tier (§5) already models — Infection's MSI gate is what prevents new Unit tests from regressing toward weak "it didn't crash" assertions over time.

**Scope exclusion:** DTO/VO constructors with only validation logic and no derived computation are excluded from the strict MSI target (`TrueValue` mutator ignore list, above) where mutation would only ever produce "the exception message wording changed" — a real but low-value mutation to chase, prioritized below actual dialect/capability logic.

---

## 7. Property-Based Testing

Used selectively, not pervasively — PHP's property-based testing ecosystem (e.g., `giorgiosironi/eris`) is less mature than PHPUnit's data-provider-based table testing, so property-based tests are reserved for the specific cases where **round-trip invariants** are the actual property under test and enumerating examples by hand would miss edge cases:

- **Quoting round-trips:** for arbitrary generated identifier strings (including edge cases: embedded quote characters, unicode, reserved words, empty-adjacent characters), `quoteIdentifier()` followed by the engine actually accepting the identifier in a real statement must never throw a syntax error, for every character-class-permuted generated identifier a property generator produces. This subsumes and generalizes the contract-test example in §4 into a fuzzed input space rather than a handful of fixed cases.
- **`DefaultValue` VO discrimination round-trips** (05 §3.2, 07 §2): for arbitrarily generated raw default-value strings from `INFORMATION_SCHEMA`-shaped fixtures, the VO must classify each into exactly one of {null, empty-string, literal, expression, sequence-next} with no input landing in more than one category — a property assertable generically rather than enumerated per fixture.
- **`ServerVersion` parsing and comparison:** for arbitrarily generated valid semver-like version strings, `isAtLeast()` comparisons must be transitive and consistent with a reference numeric-tuple comparison — a textbook property-testing use case.

Property-based tests live alongside Unit tests (`tests/Unit/**/*PropertyTest.php` naming convention) and are excluded from the mutation-testing MSI gate denominator (they are about input-space coverage, not code-path coverage, and Infection's mutation model does not map cleanly onto generator-driven tests).

---

## 8. Static Analysis as a Test Gate

Static analysis is not "nice to have" tooling run separately from tests — it is wired into the same `composer ci` script (19 §3) that gates merges, and a PR that fails PHPStan/Psalm/Rector/CS fails CI exactly as a failing PHPUnit test would.

| Tool | Level / config | What it catches that tests don't |
|---|---|---|
| PHPStan | `max` level + `phpstan-strict-rules` | Type contract violations across the whole codebase in milliseconds; the custom "no `==` on objects" rule (05 §10) is enforced here, not by a runtime test |
| Psalm | `max`/level 1 (Psalm's strictest, run alongside PHPStan for complementary coverage — the two tools' inference engines catch different bug classes in practice) | Taint analysis (relevant for the Security module, 07 §10) and more precise generic/template checking on `Collections` (05 §6) |
| Rector | dry-run only in CI (`rector process --dry-run`), never auto-applied on merge | Detects code that *could* be simplified/modernized to PHP 8.4 idioms but has drifted — a signal for maintainers, and a guard against accidentally reintroducing pre-8.4 patterns |
| PHP-CS-Fixer | PSR-12 ruleset + project additions | Formatting only — zero behavioral signal, included in the gate purely so style-only review comments never happen in PRs |
| Deptrac | `deptrac.yaml` encoding 06 §4's dependency rules | The single most architecture-relevant static check: fails the build if any application service (Metadata/Schema/DDL/Query/Execution/Import/Export) imports a concrete adapter class (Driver/Platform/Connection) instead of its interface — this is the automated enforcement of the hexagonal boundary that 06 §4 states as a rule but cannot enforce by prose alone |

```yaml
# deptrac.yaml (sketch)
deptrac:
  paths: [src]
  layers:
    - name: Contracts
      collectors: [{ type: directory, value: src/Contracts/.* }]
    - name: ApplicationServices
      collectors: [{ type: directory, value: 'src/(Metadata|Schema|DDL|Query|Execution|Import|Export)/.*' }]
    - name: Adapters
      collectors: [{ type: directory, value: 'src/(Driver|Platform|Connection)/.*' }]
  ruleset:
    ApplicationServices: [Contracts]     # allowed to depend on Contracts only
    Adapters: [Contracts]                # adapters also depend only on Contracts (implement, don't call each other)
```

---

## 9. Testing Streaming/Generators and Transactions

**Generators/streamed results** (referenced throughout `21-performance.md`) are tested by:
1. Asserting the returned type is a `\Generator` (or a class implementing `\Traversable` wrapping one) rather than an eagerly materialized array — a PHPStan rule can enforce the *return type* declares this; a runtime test additionally asserts that **memory usage does not grow linearly with row count** for a large fixture table, using `memory_get_peak_usage()` deltas across a streamed iteration vs. an intentionally-eager comparison baseline, in a dedicated Integration test tagged `#[Group('memory')]` (excluded from the default fast Integration run, included in the scheduled matrix, §11).
2. Asserting **partial consumption is safe** — starting a `foreach` over a streamed result, breaking after N rows, and confirming the underlying cursor/statement is cleanly closed (no leaked server-side cursor, no "commands out of sync" on the next query on the same connection) is itself a contract-test-tier assertion (§4), because different engines' PDO drivers have historically differed here (MySQL's unbuffered-query "must fetch all rows or close the cursor" behavior specifically, cf. `21-performance.md` §10).

**Transactions** are tested by:
1. Contract-tier assertions that `TransactionManager::run()`'s closure-based API (18 §3.7) commits on normal return and rolls back on any thrown exception, verified by asserting actual row state after the call, not by asserting the mock was invoked.
2. Contract-tier assertions for savepoint-nested transactions (07 §5's `TransactionManager` is described as "savepoint-aware transaction nesting") — starting a transaction, starting a nested one, rolling back only the inner one, and confirming the outer transaction's writes survive a subsequent commit.
3. A dedicated deadlock-simulation Integration test (two connections, interleaved locking order) that asserts a `DeadlockException` (05 §9) is thrown and is retryable-flagged, run only against engines where deadlock detection is meaningfully testable (MySQL/PostgreSQL/MSSQL; SQLite's single-writer model and Oracle's differing lock-wait model are documented as out of scope for this specific test, with capability/engine notes recorded in the test class docblock rather than silently skipped without explanation).

---

## 10. Test Data Fixtures & Schema Fixtures Per Engine

`tests/Fixtures/` holds engine-parameterized schema definitions, expressed once as SQLCraft `DdlBuilder` calls (not as raw per-engine SQL files) so that the *same* fixture-construction code exercises the DDL module under test while producing the schema every other tier tests against:

```php
namespace SQLCraft\Tests\Fixtures;

final class OrdersSchemaFixture
{
    public function __construct(private readonly DatabaseSession $db) {}

    /** Builds the canonical "orders" test table used across Integration/Contract/Golden tiers. */
    public function install(): void
    {
        $ddl = $this->db->ddl()->createTable(new QualifiedName(new Identifier('orders')))
            ->column('id', DataType::int(), autoIncrement: true, primary: true)
            ->column('customer_id', DataType::int(), nullable: false)
            ->column('status', DataType::varchar(32), nullable: false)
            ->column('total_cents', DataType::bigint(), nullable: false)
            ->column('created_at', DataType::timestamp(), nullable: false);

        $this->db->ddl()->execute($ddl);
    }

    /** @return list<array{customer_id:int,status:string,total_cents:int}> */
    public function sampleRows(): array
    {
        return [
            ['customer_id' => 1, 'status' => 'pending', 'total_cents' => 1999],
            ['customer_id' => 2, 'status' => 'paid', 'total_cents' => 4200],
            // ... enough rows to exercise pagination (18 §3.6) meaningfully
        ];
    }
}
```

Using SQLCraft's own DDL builder to construct fixtures is a deliberate double-duty: it means fixture setup is itself continuously validated against the DDL module across every engine in the Integration/Contract matrix, rather than being a separate, unvalidated per-engine SQL script that could silently drift from what `DdlBuilder` actually generates.

Larger "big schema" fixtures (hundreds/thousands of tables, referenced in `21-performance.md` §11) are generated programmatically rather than hand-authored, via a fixture-generator utility (`tests/Fixtures/LargeSchemaGenerator.php`) parameterized by table count — kept out of the default fast tiers and reserved for the dedicated performance/scale test group (§11).

---

## 11. CI Matrix

```yaml
# .github/workflows/ci.yml — runs on every push/PR (fast tier)
name: CI
on: [push, pull_request]
jobs:
  static-analysis:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.4', tools: composer, coverage: none }
      - run: composer install --prefer-dist --no-progress
      - run: composer stan
      - run: composer psalm
      - run: composer cs
      - run: composer deptrac
      - run: composer rector

  unit-and-sqlite:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: ['8.4']   # forward-compat: '8.5' added as allow-failure once 8.5 reaches RC, per §12
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '${{ matrix.php }}', extensions: pdo_sqlite, coverage: pcov }
      - run: composer install --prefer-dist --no-progress
      - run: composer test                       # unit tier
      - run: vendor/bin/phpunit --testsuite=integration --group=sqlite
      - run: vendor/bin/phpunit --coverage-clover=coverage.xml
      - uses: codecov/codecov-action@v4
```

```yaml
# .github/workflows/integration.yml — scheduled nightly + on-demand + required on main
name: Integration Matrix
on:
  schedule: [{ cron: '0 3 * * *' }]
  workflow_dispatch: {}
  push: { branches: [main] }
jobs:
  engine-matrix:
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        include:
          - { engine: mysql,     version: '5.7' }
          - { engine: mysql,     version: '8.0' }
          - { engine: mysql,     version: '8.4' }
          - { engine: mariadb,   version: '10.6' }
          - { engine: mariadb,   version: '11.4' }
          - { engine: postgres,  version: '13' }
          - { engine: postgres,  version: '14' }
          - { engine: postgres,  version: '15' }
          - { engine: postgres,  version: '16' }
          - { engine: postgres,  version: '17' }
          - { engine: mssql,     version: '2019' }
          - { engine: mssql,     version: '2022' }
          - { engine: oracle-xe, version: '21' }
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.4', extensions: 'pdo_mysql, pdo_pgsql, pdo_sqlsrv, pdo_oci' }
      - run: composer install --prefer-dist --no-progress
      - name: Run integration + contract suite against ${{ matrix.engine }}:${{ matrix.version }}
        env:
          SQLCRAFT_TEST_ENGINE: ${{ matrix.engine }}
          SQLCRAFT_TEST_ENGINE_VERSION: ${{ matrix.version }}
        run: |
          vendor/bin/phpunit --testsuite=integration --group=${{ matrix.engine }}
          vendor/bin/phpunit --testsuite=contract --group=${{ matrix.engine }}
        # Testcontainers reads SQLCRAFT_TEST_ENGINE(_VERSION) to select the image tag per job.

  os-smoke:
    runs-on: ${{ matrix.os }}
    strategy:
      matrix:
        os: [ubuntu-latest, macos-latest, windows-latest]
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.4', extensions: pdo_sqlite }
      - run: composer install --prefer-dist --no-progress
      - run: composer test   # SQLite-only smoke run per OS; full engine matrix stays Linux-only (container availability)
```

**Rationale for the two-workflow split:** static analysis + unit + SQLite integration (fast, seconds-to-low-minutes) run on *every* push/PR so contributors get immediate feedback. The full containerized engine matrix (13 engine/version combinations × contract + integration suites) is meaningfully slower and is scheduled nightly plus required before merging to `main`, plus available on-demand (`workflow_dispatch`) for a contributor who wants to validate a driver-specific change before opening a PR — this mirrors how most large PHP libraries structure CI cost against contributor iteration speed.

**PHP 8.5 forward-compat:** once PHP 8.5 reaches RC status, a `php: ['8.4', '8.5']` matrix entry is added to `unit-and-sqlite` with `continue-on-error: true` for the 8.5 leg specifically, surfacing forward-compatibility breakage early without blocking merges on a not-yet-stable PHP version — promoted to a blocking matrix entry once 8.5 is GA and PHPStan/Psalm/Rector all ship stable 8.5 support.

---

## 12. Coverage Targets

| Metric | Target | Enforced by |
|---|---|---|
| Line/branch coverage, Unit tier | ≥ 95% for `ValueObjects`, `DTO`, `Collections`, `Capabilities` (pure logic, no excuse for gaps) | `phpunit --coverage-clover` + Codecov PR check |
| Line/branch coverage, overall `src/` | ≥ 90% | Codecov PR check, non-blocking below 90% for the first two minor releases while drivers stabilize, blocking thereafter |
| Mutation Score Indicator | ≥ 80% overall, ≥ 90% for covered code | `composer infection` gate (§6) |
| Contract-suite pass rate per built-in driver | 100% — a driver that fails any conformance test is not merged | `test:contract` CI job, blocking |
| Static analysis | Zero PHPStan max / Psalm max errors | `composer stan` / `composer psalm`, blocking |

**Coverage is a floor, not a target to game.** A PR that adds trivial tests solely to inflate the line-coverage percentage without adding a meaningful assertion is treated the same as a PR with no tests in review — this is why the mutation-testing gate (§6) exists alongside the coverage gate: coverage measures "was this line executed," MSI measures "would a bug on this line have been caught," and the project treats the latter as the more meaningful number when the two disagree.
