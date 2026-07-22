# SQLCraft Planning — 03: Adminer Analysis

> Status: **Planning phase. No code exists yet.**
> Last updated: 2026-07-20
> This is the most research-heavy document in the planning set. It reverse-engineers Adminer's actual runtime behavior from source, then maps every finding to a SQLCraft design response.

---

## Scope and Method

This analysis is based on direct inspection of the legacy reference at `adminer/adminer/`. Every claim below cites the file it comes from. The goal is not to critique Adminer — it is a mature, battle-tested tool that has run on millions of servers for two decades under extreme backward-compatibility constraints (PHP 5.3+) — but to extract its *behavioral knowledge* while explicitly rejecting the *architectural patterns* that were shaped by those constraints and by its single-file-compile deployment model.

---

## 1. Request Lifecycle

Adminer's entry point (`adminer/editor/index.php` includes `adminer/include/bootstrap.inc.php`) runs a strict, order-dependent include sequence:

```
version.inc.php → errors.inc.php → coverage.inc.php
→ functions.inc.php → html.inc.php
→ [optional file.inc.php for BLOB download]
→ (define HTTPS from $_SERVER)
→ session_start() (session name "adminer_sid")
→ lang.inc.php → lang/{LANG}.inc.php
→ db.inc.php → pdo.inc.php → driver.inc.php
→ drivers/sqlite.inc.php → drivers/pgsql.inc.php
  → drivers/oracle.inc.php → drivers/mssql.inc.php
→ include/adminer.inc.php → plugins.inc.php → plugin.inc.php
→ Adminer::$instance = (plugin bootstrap or `new Adminer`)
→ drivers/mysql.inc.php   ← MUST be included last (see below)
→ define(JUSH, SERVER, DB, ME)  ← global constants derived from $_GET
→ design.inc.php → xxtea.inc.php → auth.inc.php
→ editing.inc.php → connect.inc.php
→ adminer()->afterConnect()
```

(`adminer/adminer/include/bootstrap.inc.php:1-106`)

Key observations:

- **Driver selection happens via `$_GET`, not configuration.** The constant `DRIVER` (default `"server"` for MySQL, set in `drivers/mysql.inc.php:7`) is read from the query string. `SERVER = "" . $_GET[DRIVER]` (`bootstrap.inc.php:88`). This means the "active driver" for a request is whatever key is present in the URL — `pgsql=localhost` selects PostgreSQL, `sqlite=...` selects SQLite. There is exactly one driver active per PHP process/request; Adminer was never designed to talk to two engines from the same request.
- **MySQL's driver file must be included last** (comment at `bootstrap.inc.php:84`, "must be included as last driver") because it registers as the fallback/default and its `static $jush = "sql"` assignment (`drivers/mysql.inc.php:196`) sets the global `JUSH` constant that every other file reads afterward.
- **Every operation is a `?key=` on the URL**, dispatched not through a router but through a chain of `if (isset($_GET["create"])) { include "create.inc.php"; }`-style checks in `index.php` (2.5K, one branch per operation file in `adminer/adminer/*.inc.php`: `table.inc.php`, `create.inc.php`, `indexes.inc.php`, `foreign.inc.php`, `trigger.inc.php`, `procedure.inc.php`, `event.inc.php`, `sequence.inc.php`, `user.inc.php`, `privileges.inc.php`, `sql.inc.php`, `select.inc.php`, `edit.inc.php`, `dump.inc.php`, `database.inc.php`, `scheme.inc.php`, `processlist.inc.php`, `variables.inc.php`, `call.inc.php`, `check.inc.php`, `download.inc.php`, `view.inc.php`, `script.inc.php`).
- **`page_header()`/`page_footer()` bracket every operation**, printing HTML head/nav before the operation body runs and closing tags after. Business logic (e.g., "create this table") and rendering are interleaved in the same included file — `create.inc.php` (8.8K) both validates input and echoes `<form>` markup.
- **Session is used for CSRF tokens, saved queries, and per-server login state**, then stopped before long-running output (`session_write_close()` pattern) so the session file lock does not block concurrent requests during streaming (e.g., during `dump.inc.php` export streaming).
- **CSRF + POST-redirect-GET**: state-changing operations (`create`, `edit`, `drop`) are POST requests validated against a per-session token; on success, they issue an HTTP redirect to a GET URL (`ME . "table=..."`) to avoid re-submission on refresh.

**Adminer file references:** `adminer/adminer/include/bootstrap.inc.php`, `adminer/adminer/index.php`, `adminer/adminer/include/auth.inc.php`, `adminer/adminer/include/html.inc.php`.

---

## 2. The Four-Class Model

### 2.1 `SqlDb` — Connection

`adminer/adminer/include/db.inc.php:6-56`. Abstract base with:

```php
abstract class SqlDb {
    static $instance;
    public $extension;        // extension name, e.g. "PDO_pgsql"
    public $flavor = '';      // sub-vendor, e.g. "maria", "cockroach"
    public $server_info;
    public $affected_rows = 0;
    public $info = '';
    public $errno = 0;
    public $error = '';
    protected $multi;         // multi-query result cursor

    abstract function attach(string $server, string $username, string $password): string;
    abstract function quote(string $string): string;
    abstract function select_db(string $database);
    abstract function query(string $query, bool $unbuffered = false);
    function multi_query(string $query) { ... }
    function store_result() { ... }
    function next_result(): bool { ... }
}
```

`attach()` returns an error **string** on failure rather than throwing — the caller checks `is_object($return)` to distinguish success from failure (see `driver.inc.php:38-41`: `static function connect(...) { $connection = new Db; return ($connection->attach(...) ?: $connection); }`).

`flavor` is how Adminer handles engines that share a wire protocol/API but diverge in SQL dialect or feature set — MariaDB shares MySQL's protocol but has sequences and different `SHOW` output; CockroachDB speaks the PostgreSQL wire protocol but has different DDL semantics. `flavor` is detected post-connection (typically by parsing `server_info` / `SELECT VERSION()`) and used in ad-hoc conditionals throughout driver code, e.g. `checkConstraints()` in `driver.inc.php:272`: `($this->conn->flavor == 'maria' ? " AND c.TABLE_NAME = " . q($table) : "")`.

### 2.2 `PdoDb extends SqlDb` — Shared PDO Base

`adminer/adminer/include/pdo.inc.php:6-68`. Used by every driver except native-`mysqli` MySQL. Wraps `\PDO` with `ATTR_ERRMODE => ERRMODE_SILENT` (explicitly **not** exceptions — errors are read back via `errorInfo()` after each call) and a custom statement class `PdoResult extends \PDOStatement` that adds `fetch_assoc()`/`fetch_row()`/`fetch_field()` methods matching the old `mysqli`-style API so the rest of the codebase can call one API regardless of which extension is loaded underneath.

Notably: `fetch_field()` maps `\PDO::PARAM_INT` to a legacy `mysqli`-compatible numeric type code (`0`) and everything else to `15` (string), and infers a BLOB/`charsetnr` flag from `PDO::PARAM_LOB` (`pdo.inc.php:90-96`). This is a compatibility shim bridging PDO's generic type model to mysqli's specific one — a sign that Adminer's abstraction layer was built *outward* from MySQL's original mysqli API rather than from a neutral model.

### 2.3 `SqlDriver` — Per-Engine Operations

`adminer/adminer/include/driver.inc.php:14-296`. Abstract class holding both **capability flags as public array properties** (`$insertFunctions`, `$editFunctions`, `$unsigned`, `$operators`, `$functions`, `$grouping`, `$onActions`, `$partitionBy`, `$inout`, `$generated`) and **behavior methods** (`select()`, `delete()`, `update()`, `insert()`, `insertUpdate()`, `begin()`, `commit()`, `rollback()`, `slowQuery()`, `convertSearch()`, `value()`, `quoteBinary()`, `warnings()`, `engines()`, `supportsIndex()`, `indexAlgorithms()`, `checkConstraints()`, `allFields()`).

Default implementations bake in SQL syntax assumptions the engine subclass must override (e.g. `select()` at `driver.inc.php:86-104` builds `"SELECT" . limit(...)` calling free functions `limit()` and `table()` that are themselves defined per-driver file — so the "default" implementation is only correct if the subclass has separately defined those free functions with compatible semantics; this is an implicit contract, not an enforced one).

### 2.4 `Adminer` — Customization Host

`adminer/adminer/include/adminer.inc.php`, 1100+ lines, one class. Mixes:
- **Pure logic hooks:** `credentials()`, `databases()`, `schemas()`, `operators()`, `selectQueryBuild()`, `processInput()`, `dumpTable()`, `dumpData()`, `tableName()`, `fieldName()`.
- **UI/HTML hooks:** `head()`, `bodyClass()`, `css()`, `loginForm()`, `loginFormField()`, `pluginsLinks()`, `name()` (returns an `<a>` tag with an embedded logo `<img>`).

Both categories are declared as ordinary public methods on the same class, called the same way (`adminer()->credentials()`, `adminer()->head()`). There is no type-level distinction between "logic that returns data" and "logic that echoes/returns HTML" — a static analyzer or a new contributor cannot tell which is which without reading the body or the doc-comment (`* @return string HTML code`).

### 2.5 `Plugins` — Magic Dispatch Aggregator

`adminer/adminer/include/plugins.inc.php:4-92`. Constructed with an array of plugin object instances (or auto-discovers them via `glob("adminer-plugins/*.php")` and `get_declared_classes()` reflection). Builds a `$hooks[$methodName] => [plugin, plugin, ...]` map by reflecting over every public method of a fresh `Adminer` instance and checking `method_exists($plugin, $name)` for each registered plugin (`plugins.inc.php:51-59`).

`__call()` (`plugins.inc.php:74-91`) then dispatches: for each hook name, it iterates the registered plugins in order and calls each. Two dispatch modes, selected by a **hardcoded static array** of exempted method names:

```php
private static array $append = [
    'dumpFormat' => true, 'dumpOutput' => true,
    'editRowPrint' => true, 'editFunctions' => true, 'config' => true,
];
```

- **Short-circuit mode** (default): first plugin returning non-null wins; remaining plugins are not even called for that invocation's result.
- **Append mode** (the 5 exempted names): every plugin's non-null return is merged (`$return = $value + (array) $return`).

This means whether a plugin's override is *replaceable* or *additive* is determined by a string match against a fixed list inside the dispatcher, not by anything declared on the plugin or the hook itself.

---

## 3. Global State Mechanism

Adminer explicitly avoids "global variables" (comment at `bootstrap.inc.php:32`: *"Adminer doesn't use any global variables; they used to be declared here"*) but replaces them with four other forms of global state, each with the same testability and thread-safety problems:

| Mechanism | Examples | Where |
|---|---|---|
| **`define()` constants** | `HTTPS`, `JUSH`, `SERVER`, `DB`, `ME`, `DRIVER` | `bootstrap.inc.php:43,87-98`; `mysql.inc.php:7` |
| **`$_SESSION` keyed by nested arrays** | `$_SESSION[$key][$driver][$server][$username]` for saved passwords, history, bookmarks | `auth.inc.php`, `editing.inc.php` |
| **Static class properties** | `SqlDb::$instance`, `SqlDriver::$instance`, `Adminer::$instance`, `SqlDriver::$drivers`, `SqlDriver::$jush` | `db.inc.php:7`, `driver.inc.php:14-18`, `adminer.inc.php:9` |
| **Singleton accessor functions** | `connection()`, `driver()`, `adminer()`, `connect()` | `functions.inc.php:6-29` |

`connection()` returns `Db::$instance` unless an override is explicitly passed (`functions.inc.php:6-9`); `driver()` returns `Driver::$instance` (`functions.inc.php:23-25`); `adminer()` returns `Adminer::$instance` (`functions.inc.php:17-19`, and this may actually be a `Plugins` instance masquerading behind the same accessor because of duck-typed `__call`). Any function anywhere in the 40+ include files can call `connection()->query(...)` with zero declared dependency — the call graph is invisible from a function's signature.

`JUSH` (short for "Js/hUSh" style dialect key — a legacy short code for the active dialect: `"sql"`, `"pgsql"`, `"sqlite"`, `"mssql"`, `"oracle"`) is read by dozens of functions to branch dialect-specific behavior inline, e.g. `driver.inc.php:91`: `JUSH == "sql" ? "SQL_CALC_FOUND_ROWS " : ""`.

---

## 4. Capability Detection

`support(string $feature): bool` is implemented once per driver file (e.g. `drivers/mysql.inc.php:1064`, `drivers/pgsql.inc.php:1057`) as a `preg_match` against a literal string of feature codes:

```php
// illustrative shape, mysql.inc.php:1064 area
function support(string $feature): bool {
    return (bool) preg_match(
        '~^(columns|database|drop_col|dump|event|indexes|kill|routine|'
        . 'processlist|scheme|sql|status|table|trigger|type|variables|view)$~',
        $feature
    );
}
```

There is **no registry, no enum, no compile-time list of valid feature strings.** Call sites (`support("scheme")`, `support("partial_indexes")`, `support("descidx")`) are free-text; a typo (`support("desc_idx")` instead of `"descidx"`) compiles fine and silently evaluates to `false` forever. Every driver file must independently remember and re-type the full set of ~28 known feature strings (enumerated in the prompt's capability list); there is no shared source of truth cross-checked by tooling.

---

## 5. SQL Generation & Dialect Variance

Adminer handles per-engine SQL dialect through **free functions redefined per driver file**, not through a shared interface with per-engine implementations selected polymorphically. Each driver file (`drivers/mysql.inc.php`, `drivers/pgsql.inc.php`, etc.) defines its own top-level `idf_escape()`, `table()`, `limit()`, `limit1()` functions in the same `Adminer` namespace — PHP's "last definition wins" / conditional-include behavior is what makes this work, because only one driver file is ever included per request (see §1).

| Function | MySQL (`mysql.inc.php:376-417`) | PostgreSQL (`pgsql.inc.php:390-411`) |
|---|---|---|
| `idf_escape($idf)` | backtick-quote | double-quote |
| `table($idf)` | `idf_escape($idf)` | schema-qualified: prefixes current namespace |
| `limit($query, $where, $limit, $offset, $sep)` | `LIMIT $limit OFFSET $offset` appended | same syntax, PG supports it natively too |
| `limit1($table, $query, $where, $sep)` | wraps single-row `LIMIT 1` variant for UPDATE/DELETE (MySQL supports `LIMIT` on UPDATE/DELETE) | different: PG has no `LIMIT` on UPDATE/DELETE, so `limit1` must synthesize a subquery keyed by primary key |

MSSQL needs a schema prefix in `table()` (`[schema].[table]` bracket-quoting); Oracle and SQLite have yet other `limit()` strategies (`ROWNUM` subqueries for Oracle pre-12c; SQLite supports `LIMIT`/`OFFSET` natively). `convert_field()`/`unconvert_field()` handle bidirectional value transformation for cases like PostGIS geometry columns or MySQL `POINT` types — converting the raw driver value to a display string and back for editing.

`quoteBinary()` differs by engine because binary literal syntax differs: MySQL/MariaDB use `x'...'` hex literals, PostgreSQL uses `'\x...'`  escape syntax or `decode()`, SQL Server uses `0x...`.

**Design consequence:** the mapping from "logical SQL operation" to "dialect-specific SQL text" is scattered across free functions with no shared interface contract enforcing that every driver implements every function with matching signatures. Nothing prevents one driver file from silently omitting `limit1()` (relying on a fallback in `driver.inc.php`) while another overrides it — the two are related only by naming convention.

---

## 6. Schema Introspection as Free Functions

Adminer's introspection API is a set of top-level functions per driver, not methods on a typed service:

- `tables_list()` — `mysql.inc.php:439`
- `table_status($name = "", $fast = false)` — `mysql.inc.php:459`
- `fields($table)` — `mysql.inc.php:501`
- `indexes($table, $connection2 = null)` — `mysql.inc.php:554`
- `foreign_keys($table)` — `mysql.inc.php:570`
- `triggers($table)` — `mysql.inc.php:868`
- `routines()` — `mysql.inc.php:921`
- `schemas()` — `mysql.inc.php:1111`

Each returns a bare `array` shape documented only via PHPStan `@phpstan-type` doc-comment aliases (the `TableStatus`, `Field`, `Index`, `ForeignKey`, `Trigger`, `Routine` shapes referenced in the prompt) — these aliases exist purely for static analysis; at runtime, nothing prevents a caller from accessing `$field['nul']` (typo) and silently getting a PHP warning-then-`null` instead of a compile error. Different drivers return **subtly different key sets** for the "same" concept: MySQL's `table_status()` includes `Engine`, `Auto_increment`, `Data_length`; PostgreSQL's equivalent includes `Oid`, `nspname` (schema), and no `Engine` key at all (PostgreSQL has no storage-engine concept). Code that consumes `table_status()` results and wants to be portable must defensively check `isset($status['Engine'])`.

---

## 7. Plugin Dispatch — `__call`, Short-Circuit vs Append

Already detailed in §2.5. The critical design smell: **the caller cannot know, from the call site `adminer()->dumpFormat()`, whether this is a short-circuit hook (returns one plugin's answer) or an append hook (merges all plugins' answers)** without consulting the private `$append` array inside `Plugins`. This is invisible coupling between the calling convention and a hardcoded list maintained separately from the hook declarations themselves.

---

## 8. Escaping Model

Adminer has no automatic output escaping layer. Two families of helper exist and must be manually applied at every call site:

- `h(string $s)` — HTML-escape for template output (not present in every file quoted above but used pervasively in the `*.inc.php` view files).
- `q(string $s)` / `idf_escape(string $idf)` — SQL string / SQL identifier escaping, used before building query text.

There is no templating engine enforcing escape-on-output; a contributor must remember to call `h()` around every interpolated value in an echoed HTML string. The rationale historically given (streaming, minimal dependencies, PHP 5.3-era templating options) makes sense for a tool literally designed to be a single downloadable `.php` file — but it means correctness depends on discipline at every one of hundreds of `echo`/`print` sites, not on a structural guarantee.

Note this concern doesn't map directly onto SQLCraft since SQLCraft has zero output/rendering surface — but it strongly reinforces the decision (§9 of `02-guiding-principles.md`) that SQLCraft must never echo, print, or generate HTML, precisely because that responsibility cannot be discharged safely without a dedicated templating layer that SQLCraft does not want to own.

---

## 9. Compilation Into a Single File

Adminer ships as a single compiled `.php` file for end users, produced by `compile.php` at the repo root, which concatenates every `include`d file, strips comments matched by "compile.php" markers (see the `// this is matched by compile.php` comments at `bootstrap.inc.php:6,84`), and inlines everything into one deployable artifact. This is why the include order in §1 is load-bearing: the compiler and the runtime both depend on physical file inclusion order to establish class/constant definition order, rather than an autoloader resolving dependencies by declared type.

## 10. PHP 5.3-Back-Compat Conservatism

Multiple code comments explicitly reference PHP 5.3+ compatibility constraints: `plugin.inc.php:4` — *"the overridable methods don't use return type declarations so that plugins can be compatible with PHP 5"*; `plugins.inc.php:36` — a comment about needing reflection specifically because "PHP 7.1 throws ArgumentCountError ... but older versions issue a warning." This conservatism explains many of the architectural choices that look dated by 2026 standards (loose arrays instead of typed objects, no enums, no readonly properties, string-based capability flags) — they were reasonable trade-offs for a tool needing to run on shared hosting with ancient PHP versions. SQLCraft has no such constraint: PHP 8.4 is a hard floor.

---

## Technical Debt & Hidden Coupling — Summary of the Seven Debts

| # | Debt | Manifestation | Files |
|---|---|---|---|
| 1 | Schema introspection as free functions, not methods | `tables_list()`, `fields()`, etc. are top-level namespaced functions, redefined per driver file, no shared interface | `drivers/*.inc.php` |
| 2 | Capabilities as unchecked strings + `preg_match` | `support(string $feature): bool` per driver; no registry, no enum | `drivers/mysql.inc.php:1064`, `drivers/pgsql.inc.php:1057` |
| 3 | Metadata as loose arrays | `TableStatus`, `Field`, `Index`, `ForeignKey` are `@phpstan-type` doc aliases over `array`, not real types | throughout `drivers/*.inc.php` |
| 4 | Business logic interleaved with HTML echo/streaming | `create.inc.php`, `dump.inc.php`, `select.inc.php` mix validation and `<form>`/table HTML generation | `adminer/adminer/*.inc.php` |
| 5 | State via constants/session/static | `DB`, `SERVER`, `JUSH`, `ME`, `DRIVER` constants; `$_SESSION[...]` nesting; static `::$instance` | `bootstrap.inc.php`, `auth.inc.php` |
| 6 | Single active driver per request (GET-selected) | `SERVER = "" . $_GET[DRIVER]`; one driver file included per request | `bootstrap.inc.php:88` |
| 7 | Plugin hooks via magic `__call` + method-name matching | `Plugins::__call` dispatch, hardcoded `$append` exemption list | `plugins.inc.php:74-91` |

---

## Lessons for SQLCraft — Mapping Observations to Design Responses

| Adminer Observation | SQLCraft Design Response | Planning Doc |
|---|---|---|
| Free-function introspection, no shared contract | `MetadataServiceInterface` with typed methods (`listTables()`, `listColumns()`) implemented per-driver via `IntrospectionInterface` | `09-metadata-schema.md` |
| String-keyed `support()` with no registry | `Capability` enum + `PlatformInterface::supports(Capability): bool` + `CapabilityMap` value object per platform | `08-capability-model.md` |
| Loose array metadata shapes | `readonly` VOs (`TableStatus`, `ColumnDefinition`, `IndexDefinition`, `ForeignKeyDefinition`, `TriggerDefinition`, `RoutineDefinition`) with typed constructor-promoted properties | `09-metadata-schema.md`, `18-value-objects.md` |
| Logic interleaved with HTML output | Zero rendering surface anywhere in SQLCraft; services return data only, never echo | `02-guiding-principles.md` §1, §8 |
| Global constants/session/static singletons | Constructor-injected `ConnectionInterface`/`PlatformInterface`; no `Container::getInstance()`, no `define()` | `02-guiding-principles.md` §5, `06-connection-layer.md` |
| One driver per request (GET-selected) | Multiple `ConnectionInterface` instances coexist freely within one PHP process; driver/platform is a constructor argument, not global state | `06-connection-layer.md`, `07-driver-platform.md` |
| Magic `__call` plugin dispatch with hidden append-list | PSR-14 typed event objects (`BeforeTableCreate`, `AfterExport`) dispatched through an injected `EventDispatcherInterface`; extension via explicit interface implementation, not method-name matching | `16-events.md`, `22-extension-points.md` |
| Dialect variance via redefined free functions per file | `PlatformInterface` methods (`quoteIdentifier()`, `buildLimitClause()`, `buildLimitOneClause()`) implemented once per platform class, enforced by the interface contract at compile time (via PHPStan) | `07-driver-platform.md` |
| `flavor` string for sub-vendor gating (maria/cockroach) | Platform composition: `MariaDBPlatform` composes/extends `MySQLPlatform`'s shared behavior via an explicit `DialectVariant` value or a dedicated `MariaDBPlatform` class implementing the same `PlatformInterface`, never an untyped string compared with `==` | `07-driver-platform.md` |
| `attach()` returns error string instead of throwing | All connection failures throw `ConnectionFailedException` with the driver's native error message attached | `02-guiding-principles.md` §8, `17-exceptions.md` |
| No compile-time capability validation | `Capability` enum makes invalid feature names a `\ValueError` at the language level, not a silent `false` | `08-capability-model.md` |
| Single compiled file / include-order dependency | Composer PSR-4 autoloading; class resolution by type, not include order; no compilation step required for correctness | `05-namespace-structure.md` |
| PHP 5.3 back-compat conservatism | PHP 8.4 hard floor; no BC concern for legacy PHP versions | `02-guiding-principles.md` §11 |
