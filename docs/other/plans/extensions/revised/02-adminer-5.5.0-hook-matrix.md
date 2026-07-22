# Adminer 5.5.0 Hook → SQLCraft Capability Matrix

> **Status:** Normative parity inventory
> **Date:** 2026-07-22
> **Adminer source:** `adminer/adminer/include/adminer.inc.php`
> **Plugin composition source:** `adminer/adminer/include/plugins.inc.php`
> **SQLCraft plan:** `01-extension-system-plan.md`

## 1. Baseline and Rules

This matrix is exhaustive for the 79 public methods on the vendored Adminer 5.5.0
extension object. Capability parity does not mean preserving Adminer's method
names, inheritance API, or plugin dispatch behavior.

Adminer's `Adminer\Plugins` aggregator uses:

- configured plugin order;
- the default `Adminer` implementation last;
- first non-`null` result for normal hooks;
- array merging for `dumpFormat`, `dumpOutput`, `editRowPrint`, `editFunctions`,
  and `config`.

SQLCraft uses typed builder registrations, adapters, ordered pipelines, and direct
configuration instead. The dispatch column documents origin behavior only.

### Defects in the superseded mapping

The previous "complete" table omitted these 22 current hooks:

`name`, `css`, `foreignKeys`, `sqlCommandQuery`, `sqlPrintAfter`, `rowDescription`, `selectLink`, `selectVal`, `editVal`, `tableIndexesPrint`, `selectColumnsPrint`, `selectSearchPrint`, `selectOrderPrint`, `selectLimitPrint`, `selectLengthPrint`, `messageQuery`, `editRowPrint`, `dumpFilename`, `dumpFooter`, `syntaxHighlighting`, `databasesPrint`, `menuActions`.

It listed these 11 hooks that do not exist in Adminer 5.5.0:

`cssLinks`, `processInputs`, `tablePrint`, `tableStructureProcesses`, `tableStructureBeforeColumns`, `tableStructureAfterColumns`, `tableStructureAfterConstraints`, `selectQueryPrint`, `dumpFooters`, `privileges`, `rowCount`.

It also mapped `selectQuery()` to pre-execution SQL rewriting. In Adminer,
`selectQuery()` produces presentation output for a query; SQLCraft's ordered
query interceptor is an independent SQLCraft-native seam.

## 2. Classification Totals

| Classification | Count |
|---|---:|
| SQLCraft extension seam or adapter | 18 |
| Direct configuration or ordinary core operation | 11 |
| Excluded UI/HTTP/form/presentation behavior | 46 |
| Excluded application authentication/session behavior | 3 |
| Package metadata | 1 |
| **Total** | **79** |

## 3. Complete Matrix

| Adminer hook/signature | Adminer purpose | Adminer dispatch | Disposition | SQLCraft target | Current state | Required plan action |
|---|---|---|---|---|---|---|
| `name(): string` | Default Adminer plugin; it should call methods via adminer()->f() instead of $this->f() to give chance to other plugins | first non-null | Package metadata | Composer package/class metadata | No runtime plugin object is planned. | Keep out of the runtime extension interface. |
| `credentials(): array` | Connection parameters | first non-null | Extension seam/adapter | `CredentialProviderInterface` plus nullable-miss chain | Partial: single provider is wired; chain and miss semantics are absent. | Make `resolve()` nullable; chain falls through only on `null`; errors propagate. |
| `connectSsl()` | Get SSL connection options | first non-null | Direct config/core operation | `ConnectionParameters::$ssl` | Live direct configuration. | Document as configuration, not extension registration. |
| `permanentLogin(bool $create = false): string` | Get key used for permanent login | first non-null | Excluded: application auth/session | Consumer authentication/session layer | Intentionally absent from SQLCraft. | Keep excluded; credentials are not authorization. |
| `bruteForceKey(): string` | Return key used to group brute force attacks; behind a reverse proxy, you want to return the last part of X-Forwarded-For | first non-null | Excluded: application auth/session | Consumer authentication/session layer | Intentionally absent from SQLCraft. | Keep excluded; credentials are not authorization. |
| `serverName(?string $server): string` | Get server name displayed in breadcrumbs | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `database(): ?string` | Identifier of selected database | first non-null | Direct config/core operation | `ConnectionParameters::$database` | Live direct configuration. | Document as configuration, not extension registration. |
| `databases(bool $flush = true): array` | Get cached list of databases | first non-null | Extension seam/adapter | `MetadataInspectorSet` / `ServerInspectorInterface` | Partial: inspector exists; factory hardcodes the inspector graph. | Create/decorate one per-connection metadata-inspector set. |
| `pluginsLinks(): void` | Print links after list of plugins | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `operators(): array` | Operators used in select | first non-null | Extension seam/adapter | Composed platform `QueryDialectInterface` | Live core behavior; small overrides require the 85-method platform aggregate. | Move operator behavior into replaceable query-dialect role. |
| `schemas(): array` | Get list of schemas | first non-null | Extension seam/adapter | `MetadataInspectorSet` / `DatabaseInspectorInterface` | Partial: inspector exists; factory hardcodes the inspector graph. | Create/decorate one per-connection metadata-inspector set. |
| `queryTimeout(): float` | Specify limit for waiting on some slow queries like DB list | first non-null | Direct config/core operation | Execution options/policy and connection initializer | Partial: explicit timeout execution exists; no unified factory policy. | Define timeout policy and initializer use; do not map to slow-query detection. |
| `afterConnect(): void` | Called after connecting and selecting a database | first non-null | Extension seam/adapter | Ordered `ConnectionInitializerInterface` chain | Gap: opened event has no connection and no initializer lifecycle. | Add ordered initializers with cleanup and failure events. |
| `headers(): void` | Headers to send before HTML output | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `csp(array $csp): array` | Get Content Security Policy headers | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `head(?bool $dark = null): bool` | Print HTML code inside <head> | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `bodyClass(): void` | Print extra classes in <body class>; must start with a space | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `css(): array` | Get URLs of the CSS files | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `loginForm(): void` | Print login form | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `loginFormField(string $name, string $heading, string $value): string` | Get login form field | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `login(string $login, string $password)` | Authorize the user | first non-null | Excluded: application auth/session | Consumer authentication/session layer | Intentionally absent from SQLCraft. | Keep excluded; credentials are not authorization. |
| `tableName(array $tableStatus): string` | Table caption used in navigation and headings | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `fieldName(array $field, int $order = 0): string` | Field caption used in select and edit | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `selectLinks(array $tableStatus, ?string $set = ""): void` | Print links after select heading | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `foreignKeys(string $table): array` | Get foreign keys for table | first non-null | Extension seam/adapter | `MetadataInspectorSet` / `ForeignKeyInspectorInterface` | Partial: inspector exists; replacement is not live through the factory. | Wire decorated inspector set into schema and export paths. |
| `backwardKeys(string $table, string $tableName): array` | Find backward keys for table | first non-null | Extension seam/adapter | `ForeignKeyInspectorInterface::getReferencingKeys()` | Implemented at low level; shares the hardcoded metadata graph problem. | Wire decorated inspector set into schema and export paths. |
| `backwardKeysPrint(array $backwardKeys, array $row): void` | Print backward keys for row | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `selectQuery(string $query, float $start, bool $failed = false): string` | Query printed in select before execution | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `sqlCommandQuery(string $query): string` | Query printed in SQL command before execution | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `sqlPrintAfter(): void` | Print HTML code just before the Execute button in SQL command | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `rowDescription(string $table): string` | Description of a row in a table | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `rowDescriptions(array $rows, array $foreignKeys): array` | Get descriptions of selected data | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `selectLink(?string $val, array $field)` | Get a link to use in select table | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `selectVal(?string $val, ?string $link, array $field, ?string $original): string` | Value printed in select table | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `editVal(?string $val, array $field): ?string` | Value conversion used in select and edit | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `config(): array` | Get configuration options for AdminerConfig | append/array merge | Direct config/core operation | `SQLCraftBuilder` configuration | Gap until the builder becomes the canonical composition root. | Builder replaces Adminer config-array merging. |
| `tableStructurePrint(array $fields, ?array $tableStatus = null): void` | Print table structure in tabular format | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `tableIndexesPrint(array $indexes, array $tableStatus): void` | Print list of indexes on table in tabular format | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `selectColumnsPrint(array $select, array $columns): void` | Print columns box in select | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `selectSearchPrint(array $where, array $columns, array $indexes): void` | Print search box in select | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `selectOrderPrint(array $order, array $columns, array $indexes): void` | Print order box in select | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `selectLimitPrint(int $limit): void` | Print limit box in select | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `selectLengthPrint(string $text_length): void` | Print text length box in select | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `selectActionPrint(array $indexes): void` | Print action box in select | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `selectCommandPrint(): bool` | Print command box in select | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `selectImportPrint(): bool` | Print import box in select | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `selectEmailPrint(array $emailFields, array $columns): void` | Print extra text in the end of a select form | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `selectColumnsProcess(array $columns, array $indexes): array` | Process columns box in select | first non-null | Direct config/core operation | Caller-built select/query value objects | Caller-owned; query construction exists. | Document typed query construction; no hook. |
| `selectSearchProcess(array $fields, array $indexes): array` | Process search box in select | first non-null | Direct config/core operation | Caller-built conditions and validated operators | Caller-owned; query construction and operator validation exist. | Document typed query construction; no hook. |
| `selectOrderProcess(array $fields, array $indexes): array` | Process order box in select | first non-null | Direct config/core operation | Caller-built order clauses | Caller-owned; query construction exists. | Document typed query construction; no hook. |
| `selectLimitProcess(): int` | Process limit box in select | first non-null | Direct config/core operation | Pagination parameters | Caller-owned; paginator/query options exist. | Document typed pagination; no hook. |
| `selectLengthProcess(): string` | Process length box in select | first non-null | Direct config/core operation | Caller/export/query options | Caller-owned; no Adminer form state is modeled. | Document caller-owned options; no hook. |
| `selectEmailProcess(array $where, array $foreignKeys): bool` | Process extras in select form | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `selectQueryBuild(array $select, array $where, array $group, array $order, int $limit, ?int $page): string` | Build SQL query used in select | first non-null | Direct config/core operation | Typed query builders/renderers; ordered interceptors after rendering | Core query builders exist; current event SQL mutation is unordered. | Use ordered interceptor chain for post-render transformations. |
| `messageQuery(string $query, string $time, bool $failed = false): string` | Query printed after execution in the message | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `editRowPrint(string $table, array $fields, $row, ?bool $update): void` | Print before edit form | append/array merge | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `editFunctions(array $field): array` | Functions displayed in edit form | append/array merge | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `editInput(?string $table, array $field, string $attrs, $value): string` | Get options to display edit field | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `editHint(?string $table, array $field, ?string $value): string` | Get hint for edit field | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `processInput(array $field, string $value, ?string $function = ""): string` | Process sent input | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `dumpOutput(): array` | Return export output options | append/array merge | Extension seam/adapter | Operation-supplied `SinkInterface` | Live: sinks are supplied per export operation. | Document operation lifetime; no global sink registry. |
| `dumpFormat(): array` | Return export format options | append/array merge | Extension seam/adapter | Named writer/reader factories | Partial: registry exists but factory-created sessions hardcode formats. | Register named factories and create fresh adapters. |
| `dumpDatabase(string $db): void` | Export database structure | first non-null | Extension seam/adapter | `Exporter` plus `ExportSourceInterface` | Built-in path live; third-party format/source registration is not live. | Use builder-supplied metadata and format factories. |
| `dumpTable(string $table, string $style, int $is_view = 0): void` | Export table structure | first non-null | Extension seam/adapter | `Exporter`/`TableDumper` plus writer adapter | Built-in path live; third-party format/source registration is not live. | Use builder-supplied metadata and format factories. |
| `dumpData(string $table, string $style, string $query): void` | Export table data | first non-null | Extension seam/adapter | `FormatWriterInterface::writeRows()` | Built-in path live; writer registration and lifecycle are not configurable. | Use fresh writer instances; validate factory/name agreement. |
| `dumpFilename(string $identifier): string` | Set export filename | first non-null | Direct config/core operation | Caller-owned file path/sink naming | Caller-owned; SQLCraft does not own HTTP download naming. | Document caller-owned naming; no hook. |
| `dumpHeaders(string $identifier, bool $multi_table = false): string` | Send headers for export | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `dumpFooter(): void` | Print text after export | first non-null | Extension seam/adapter | `FormatWriterInterface::writeFooter()` | Writer contract exists; custom writer registration is not live. | Use fresh writer instances; validate lifecycle reset. |
| `importServerPath(): string` | Set the path of the file for webserver load | first non-null | Extension seam/adapter | `ImportSourceInterface` | Live through explicit import source objects. | Document custom source implementation; no server-path global. |
| `homepage(): bool` | Print homepage | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `navigation(string $missing): void` | Print navigation after Adminer title | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `syntaxHighlighting(array $tables): void` | Set up syntax highlight for code and <textarea> | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `databasesPrint(string $missing): void` | Print databases list in menu | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `menuActions(array $actions, string $missing): array` | Print table list in menu | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `tablesPrint(array $tables): void` | Print table list in menu | first non-null | Excluded: UI/HTTP/form/presentation | Consumer application/UI layer | Intentionally absent from SQLCraft. | Keep excluded; migration guide explains the ownership boundary. |
| `showVariables(): array` | Get server variables | first non-null | Extension seam/adapter | `ServerInspectorInterface::getVariables()` | Concrete manager supports it; public schema interface does not expose it. | Expose live schema/server caller surface and inspector decoration. |
| `showStatus(): array` | Get status variables | first non-null | Extension seam/adapter | `ServerInspectorInterface::getStatus()` | Concrete manager supports it; public schema interface does not expose it. | Expose live schema/server caller surface and inspector decoration. |
| `processList(): array` | Get process list | first non-null | Extension seam/adapter | `ServerInspectorInterface::getProcessList()` | Concrete manager supports it; public schema interface does not expose it. | Expose live schema/server caller surface and inspector decoration. |
| `killProcess(string $id)` | Kill a process | first non-null | Extension seam/adapter | Connection-scoped `ProcessManagerInterface` | Gap: capability exists without a reachable operation. | Add process manager or remove `Capability::Kill` until implemented. |

## 4. Migration Notes for Representative Plugins

### `sql-log.php`

Adminer's plugin logs from presentation/message hooks. SQLCraft migration uses
`QueryHistoryInterface` or query success/failure events. It does not depend on
`selectQuery()` or `sqlCommandQuery()` compatibility.

### `timeout.php`

Adminer's plugin uses post-connect setup. SQLCraft migration uses a connection
initializer for session-level database settings and explicit query timeout policy
for operation-level limits. `SlowQueryDetectedEvent` is observability, not timeout
enforcement.

### `login-servers.php`

The Adminer plugin primarily controls a login-form server selector. SQLCraft has
no login form. Server definitions remain application configuration; credential
fallback uses the nullable-miss provider chain.

### `database-hide.php`

Adminer's own plugin describes itself as presentation-only, not security.
SQLCraft leaves collection filtering to the consumer UI and does not add a schema
visibility policy in this plan.

### `backward-keys.php`

Adminer's plugin combines referencing-key discovery with HTML links. SQLCraft
already models discovery through `getReferencingKeys()`; link rendering remains a
consumer concern.

### Dump plugins

Adminer dump plugins often combine format registration, HTTP headers, buffering,
and output. SQLCraft splits these responsibilities:

- named writer factory for the format;
- caller-supplied sink for output/compression;
- caller-owned filename and HTTP response;
- fresh writer instance for each operation.

## 5. Drift Validation

Add a plan/architecture check that:

1. Extracts all `public function` names from the vendored
   `Adminer\Adminer` source.
2. Parses the first column of this matrix.
3. Fails if either set has missing, duplicate, or extra names.
4. Asserts the total remains 79 for the 5.5.0 baseline.
5. Requires an explicit baseline/version update when vendored Adminer changes.

The check validates inventory, not the subjective disposition text. Disposition
changes require ADR review.
