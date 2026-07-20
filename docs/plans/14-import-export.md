# 14 — Import / Export

> **Status:** Design draft
> **Scope:** `SQLCraft\Export` and `SQLCraft\Import` namespaces — `Exporter`/`Dumper`, `FormatWriterInterface`/`FormatReaderInterface`, output sinks, `DumpOptions`, `TableDumper`, `Importer`, `StatementSplitter` state machine, `BatchExecutor` integration, progress reporting, resource limits
> **Depends on:** 05-domain-model.md (DTOs), 08-driver-architecture.md (DdlDialectInterface), 09-capability-model.md (Capability), 10-connection-layer.md (ConnectionInterface, ResultInterface), 11-schema-services.md (SchemaManager, TableInspectorInterface), 12-query-engine.md (QueryExecutor, StatementBatch, BatchExecutor), 13-ddl-services.md (DdlBuilder), 16-events.md (Import/Export events)
> **Namespace root:** `SQLCraft\Export`, `SQLCraft\Import`

---

## 1. Design Goals

1. **No output target assumption.** Adminer writes directly to `php://output` via `echo`/headers because it is a web application. SQLCraft is consumed as a library; it never assumes an HTTP response exists. Every export writes to an injectable sink.
2. **Streaming by default, memory-bounded always.** A dump of a multi-gigabyte table must not buffer the whole result set or the whole output in memory. Rows are read from the connection and written to the sink incrementally.
3. **Format independence.** SQL, CSV, TSV, JSON, and XML are treated as interchangeable output formats behind a single `FormatWriterInterface`. Adding Parquet or XLSX support should not require touching the exporter's orchestration logic.
4. **Proper statement splitting on import.** Adminer's 100KB-chunk-plus-delimiter-scan approach is replaced with a real state machine that correctly handles quoted strings, comments, and `DELIMITER` changes without the chunk-boundary edge cases inherent in fixed-size reads.
5. **Composable with the rest of SQLCraft.** Export DDL generation reuses `DdlBuilder` (13-ddl-services.md); import execution reuses `BatchExecutor` (12-query-engine.md §4.2); progress reporting reuses the PSR-14 event system (16-events.md §5.5).

---

## 2. Export Architecture

### 2.1 Output Sinks

An output sink is anywhere bytes can be written. SQLCraft never writes to `php://output` or sets HTTP headers — that remains the consumer's responsibility (a controller action decorates a `SinkInterface` with headers and streams it to the browser if it wants browser delivery).

```php
namespace SQLCraft\Contracts\Export;

interface SinkInterface
{
    /** Write a chunk of bytes. Called repeatedly as the dump streams. */
    public function write(string $bytes): void;

    /** Flush any internal buffering (e.g., gzip flush) without closing. */
    public function flush(): void;

    /** Finalize the sink (close gzip stream, close file handle, etc.). */
    public function close(): void;
}
```

**Built-in sinks:**

| Class | Target | Notes |
|-------|--------|-------|
| `ResourceSink` | Any PHP stream resource (`fopen()` handle, `php://memory`, `php://temp`, an already-open file handle) | The general-purpose sink; consumer supplies the resource, including `php://output` if they explicitly want that |
| `Psr7StreamSink` | A PSR-7 `StreamInterface` | For frameworks that model HTTP responses as PSR-7 streams (Slim, Mezzio, Guzzle-based apps) |
| `StringBufferSink` | In-memory string accumulator | Convenience for small dumps (e.g., exporting one table's DDL for a preview UI); explicitly documented as memory-unsafe for large exports |
| `GzipSink` | Decorates another `SinkInterface`, compressing via `zlib` (`gzencode`/streaming deflate) | Requires `ext-zlib`; constructor throws `ExtensionMissingException` if unavailable — never silently falls back to uncompressed output |
| `Bzip2Sink` | Decorates another `SinkInterface`, compressing via `ext-bz2` | Same graceful-failure contract as `GzipSink` |
| `MultiFileSink` | Wraps a directory + naming callback; used for `tar`-equivalent "one file per table" scope (CSV-per-table); does not itself produce a tar archive — see §2.7 | |

```php
// Consumer wiring HTTP delivery — SQLCraft has no opinion on this; shown for illustration only
$response->getBody(); // PSR-7 stream
$sink = new Psr7StreamSink($response->getBody());
$exporter->exportTable($conn, $table, $sink, $options);
// Consumer sets Content-Disposition / Content-Encoding headers itself.
```

### 2.2 `FormatWriterInterface`

Every export format implements this interface. The `Exporter` drives the writer through a table-by-table, row-by-row lifecycle; the writer decides how to render each callback into bytes.

```php
namespace SQLCraft\Contracts\Export;

use SQLCraft\DTO\{TableStatus, ColumnMeta};

interface FormatWriterInterface
{
    /** Unique format identifier: 'sql', 'csv', 'csv-semicolon', 'tsv', 'json', 'xml'. */
    public function getFormatName(): string;

    /** Called once at the start of the whole export (all scopes). */
    public function writeHeader(SinkInterface $sink, DumpOptions $options): void;

    /** Called once per table/view before its DDL/rows. */
    public function writeTableHeader(SinkInterface $sink, TableStatus $table, DumpOptions $options): void;

    /** Called once per table with its CREATE DDL (SQL format only meaningfully renders this). */
    public function writeTableDdl(SinkInterface $sink, TableStatus $table, array $ddlStatements): void;

    /**
     * Called once per row batch (default batch size: 100, matching Adminer).
     * @param list<array<string, mixed>> $rows
     * @param list<ColumnMeta>           $columns
     */
    public function writeRows(SinkInterface $sink, TableStatus $table, array $rows, array $columns, DumpOptions $options): void;

    /** Called once per table after all rows are written. */
    public function writeTableFooter(SinkInterface $sink, TableStatus $table): void;

    /** Called once at the very end of the export. */
    public function writeFooter(SinkInterface $sink, DumpOptions $options): void;
}
```

**Why a callback-lifecycle interface rather than "return a string per row":** returning strings would force the `Exporter` to know format-specific concerns like whether a JSON array needs a comma between rows or whether a CSV header row is needed once per file. Delegating full write control (including whitespace/separators) to the writer keeps that logic inside the format implementation, matching the single-responsibility split already used for `DdlDialectInterface` (13-ddl-services.md).

### 2.3 `DumpOptions` VO

```php
namespace SQLCraft\Export;

enum DatabaseSectionStyle { case None; case Use; case DropCreate; case Create; }
enum TableSectionStyle    { case None; case DropCreate; case Create; }
enum DataStyle            { case None; case TruncateInsert; case Insert; case InsertUpdate; }

final readonly class DumpOptions
{
    /**
     * @param list<string>|null $tables    Null = all tables in scope
     * @param list<string>|null $databases Null = current database only; non-null = multi-database scope
     */
    public function __construct(
        public readonly string               $format,             // 'sql' | 'csv' | 'csv-semicolon' | 'tsv' | 'json' | 'xml' | custom
        public readonly DumpScope             $scope,              // see §2.4
        public readonly DatabaseSectionStyle  $databaseStyle    = DatabaseSectionStyle::None,
        public readonly TableSectionStyle     $tableStyle       = TableSectionStyle::DropCreate,
        public readonly DataStyle             $dataStyle        = DataStyle::Insert,
        public readonly bool                  $includeAutoIncrement = true,
        public readonly bool                  $includeTriggers      = false,
        public readonly bool                  $includeRoutines      = false,
        public readonly bool                  $includeEvents        = false,   // MySQL/MariaDB scheduler events
        public readonly bool                  $includeUserTypes     = false,   // PgSQL CREATE TYPE
        public readonly int                   $batchSize            = 100,     // rows per INSERT/output batch
        public readonly ?string               $csvSeparator         = null,    // null = format default (',' or '\t')
        public readonly string                $nullRepresentation   = '\\N',   // SQL/CSV NULL token
    ) {}
}
```

```php
final readonly class DumpScope
{
    private function __construct(
        public readonly ScopeKind $kind,
        public readonly ?string   $database = null,
        public readonly ?array    $tables   = null,      // list<string>, non-null only for Table/Tables kinds
        public readonly ?string   $resultSql = null,      // non-null only for FilteredResult kind
    ) {}

    public static function allDatabases(): self { return new self(ScopeKind::AllDatabases); }
    public static function database(string $database): self { return new self(ScopeKind::Database, database: $database); }
    public static function tables(string $database, array $tables): self { return new self(ScopeKind::Tables, database: $database, tables: $tables); }
    public static function table(string $database, string $table): self { return new self(ScopeKind::Tables, database: $database, tables: [$table]); }
    public static function filteredResult(string $database, string $table, string $sql): self
    {
        return new self(ScopeKind::FilteredResult, database: $database, tables: [$table], resultSql: $sql);
    }
}

enum ScopeKind { case AllDatabases; case Database; case Tables; case FilteredResult; }
```

`DumpScope::filteredResult()` covers exporting the current result of a browse/WHERE-filtered query — Adminer supports dumping "just this filtered view of a table" from its select screen; SQLCraft models it as a scope variant rather than a separate code path, since the row-fetching mechanics are identical to a full-table dump except the SELECT has a WHERE clause.

### 2.4 `TableDumper` — Per-Table Orchestration

```php
namespace SQLCraft\Export;

final class TableDumper
{
    public function __construct(
        private readonly SchemaManager         $schema,
        private readonly ConnectionInterface   $conn,
        private readonly DdlBuilderFactory     $ddl,        // builds CreateTableBuilder/TruncateBuilder from TableStatus
        private readonly ?EventDispatcherInterface $events = null,
    ) {}

    public function dump(TableStatus $table, FormatWriterInterface $writer, SinkInterface $sink, DumpOptions $options): void
    {
        $writer->writeTableHeader($sink, $table, $options);

        if ($options->tableStyle !== TableSectionStyle::None) {
            $ddlStatements = $this->buildTableDdl($table, $options);
            $writer->writeTableDdl($sink, $table, $ddlStatements);
        }

        if ($options->dataStyle !== DataStyle::None && !$table->isView) {
            $this->dumpRows($table, $writer, $sink, $options);
        }

        if ($options->includeTriggers) {
            $this->dumpTriggers($table, $writer, $sink);
        }

        $writer->writeTableFooter($sink, $table);
    }

    /** @return list<string> */
    private function buildTableDdl(TableStatus $table, DumpOptions $options): array
    {
        $statements = [];
        if ($options->tableStyle === TableSectionStyle::DropCreate) {
            $statements[] = (new DropTableBuilder($table->qualifiedName(), ifExists: true))
                ->toSql($this->conn->getPlatform())[0];
        }
        $createBuilder = $this->ddl->createTableBuilderFor($table)
            ->withAutoIncrementValue($options->includeAutoIncrement ? $table->autoIncrement : null);
        $statements = [...$statements, ...$createBuilder->toSql($this->conn->getPlatform())];
        return $statements;
    }

    private function dumpRows(TableStatus $table, FormatWriterInterface $writer, SinkInterface $sink, DumpOptions $options): void
    {
        if ($options->dataStyle === DataStyle::TruncateInsert) {
            $writer->writeTableDdl($sink, $table, (new TruncateBuilder($table->qualifiedName()))->toSql($this->conn->getPlatform()));
        }

        $columns = $this->schema->getColumns($this->conn, $table->qualifiedName());
        $result  = $this->conn->query("SELECT * FROM " . $this->conn->quoteIdentifier($table->name), streaming: true);

        $batch = [];
        foreach ($result as $row) {
            $batch[] = $row;
            if (count($batch) >= $options->batchSize) {
                $writer->writeRows($sink, $table, $batch, $columns->toArray(), $options);
                $batch = [];
            }
        }
        if (!empty($batch)) {
            $writer->writeRows($sink, $table, $batch, $columns->toArray(), $options);
        }
    }
}
```

**Streaming guarantee:** `dumpRows()` uses `$streaming: true` on `ConnectionInterface::query()` (10-connection-layer.md §7) and never materializes more than `$options->batchSize` rows in memory — the default 100 matches Adminer's INSERT batching exactly, chosen for the same reason (bounded statement size, bounded memory, still amortizes round-trip and parsing overhead across multiple rows per statement).

### 2.5 `Exporter` — Top-Level Orchestration and PgSQL FK Ordering

```php
namespace SQLCraft\Contracts\Export;

interface ExporterInterface
{
    /**
     * Stream a full export (per DumpOptions scope) to the given sink.
     * Never returns a string — always writes through the sink.
     * Fires ExportStartedEvent / ExportProgressEvent / ExportFinishedEvent (16-events.md §5.5).
     */
    public function export(ConnectionInterface $conn, SinkInterface $sink, DumpOptions $options): void;
}
```

Ordering contract inside `Exporter::export()`:

1. `writer->writeHeader()` — file-level preamble (SQL: comment header + optional `SET` statements; JSON: opening `{`; XML: `<?xml ...?>` + root element).
2. Database-section DDL per `DatabaseSectionStyle` (`USE`/`\c`/`CREATE DATABASE`) via `UseDatabaseBuilder`/`CreateDatabaseBuilder` (13-ddl-services.md §2.3).
3. **Tables**, in dependency order where determinable (tables with no incoming FK first) — but see the PgSQL exception below.
4. **Views**, always after all tables (a view's SELECT may reference any table; dumping views first risks referencing not-yet-created tables on replay).
5. **Triggers, routines, events** (if included), after their owning tables/views.
6. `writer->writeFooter()`.

**PostgreSQL foreign key ordering:** determining a fully-correct topological table order for FK dependencies is possible but fragile in the presence of circular references (table A has an FK to B, B has an FK to A — legal in PgSQL, impossible to linearize). Adminer's PgSQL driver sidesteps this by appending all `ALTER TABLE ... ADD FOREIGN KEY` statements *after every table has been created*, rather than inlining FKs into each table's `CREATE TABLE`. SQLCraft's `Exporter` replicates this for PgSQL specifically:

```php
// Exporter::export() — PgSQL-specific FK deferral
if ($this->conn->getPlatformName() === 'pgsql') {
    // CreateTableBuilder for PgSQL scope renders WITHOUT inline foreign key clauses;
    // TableDumper collects each table's ForeignKeyMeta list instead of rendering them inline.
    $deferredForeignKeys = [];
    foreach ($tablesInScope as $table) {
        $deferredForeignKeys[] = ...$this->schema->getForeignKeys($conn, $table->qualifiedName());
        $this->tableDumper->dump($table, $writer, $sink, $options->withoutInlineForeignKeys());
    }
    foreach ($deferredForeignKeys as $fk) {
        $writer->writeTableDdl($sink, $fk->table, [
            (new AlterTableBuilder($fk->table))->withAddForeignKey($fk)->toSql($conn->getPlatform())[0],
        ]);
    }
} else {
    // MySQL/SQLite/MSSQL/Oracle: FKs inline in CREATE TABLE is safe because
    // SQLCraft topologically sorts tables by non-circular FK dependency first,
    // falling back to declaration order if a cycle is detected (with a warning event).
}
```

For non-PgSQL engines, `TopologicalTableSorter` (an internal helper) orders tables by FK dependency; if a cycle is detected, it falls back to alphabetical/declaration order and emits an `ExportWarningEvent` noting that some FKs may fail on first pass and require re-running (matching the reality that a true cycle is inherently unsolvable by reordering alone — those tables need the same "add FK after" treatment, applied only to the cyclic subset).

### 2.6 Scope Combinations

| Scope | `getTables()` call pattern | Iteration |
|-------|---------------------------|-----------|
| All databases | `ServerInspectorInterface::getDatabases()` then `getTables()` per database | Outer loop over databases, inner loop over tables; database-section DDL emitted between each |
| Single database, all tables | `TableInspectorInterface::getTables($conn)` | Single loop |
| Selected tables | Caller-supplied table name list, resolved via `getTableStatus()` per name | Single loop over the supplied list, preserving caller-given order |
| Single table | Same as above with one entry | Trivial case of "selected tables" |
| Filtered result set | No `TableInspectorInterface` call — DDL section skipped entirely (data style forced to `Insert`, since there is no complete table to TRUNCATE); rows come from executing `DumpScope::$resultSql` instead of `SELECT * FROM table` | `TableDumper::dumpRows()` is invoked directly with the custom SQL, bypassing `dump()`'s DDL section |

### 2.7 Multi-File / Archive Output

Adminer's "tar" output produces one CSV file per table inside a tar archive, generated on the fly. SQLCraft splits this into two composable concerns rather than one monolithic "tar mode":

1. **`MultiFileSink`** — given a format that produces one logical file per table (CSV, TSV, JSON when configured per-table), routes each table's byte stream to a separate `SinkInterface` instance (e.g., one file handle per table in a directory).
2. **Archive assembly is explicitly out of scope for the core `Exporter`.** SQLCraft does not ship a tar/zip writer. A consumer wanting a single downloadable archive wraps `MultiFileSink` to write into a temp directory, then uses `ext-phar` or the `symfony/filesystem` + system `tar` or any archiving library of their choice to package the result. This keeps SQLCraft free of an archive-format dependency for a feature (packaging files) that is one line of glue code for any consumer who needs it, while the actually-hard part (streaming per-table CSV generation) remains SQLCraft's job.

---

## 3. SQL Format Writer

### 3.1 Object Order and Inclusions

The `SqlFormatWriter` is the most complex writer because it must emit a syntactically correct and replay-orderable SQL file. Object emission order per table/view:

1. Comment header: `-- Table: orders` (or `-- View: orders_summary`).
2. `DROP TABLE IF EXISTS` / `DROP VIEW IF EXISTS` (when `$tableStyle = DropCreate`).
3. `CREATE TABLE ...` (with all columns, primary key, unique constraints, check constraints; inline indexes via `UNIQUE KEY` on MySQL; separate `CREATE INDEX` statements for non-inline index types come after the `CREATE TABLE` on all engines).
4. `CREATE INDEX ...` (one statement per non-primary, non-unique-inline index).
5. `ALTER TABLE ... ADD CONSTRAINT ... FOREIGN KEY ...` — PgSQL: deferred to end; other engines: inline after the `CREATE TABLE` block for that table.
6. Optional: `TRUNCATE TABLE` / `DELETE FROM` (when `$dataStyle = TruncateInsert`).
7. INSERT batches (100 rows per statement by default).
8. Optional: `CREATE TRIGGER ...` statements (with DELIMITER wrapping on MySQL if trigger body contains semicolons).
9. After all tables: `CREATE VIEW ...` for views; then routines, events, user types (each guarded by capability check — if the connection doesn't support it, the section is silently skipped at the object level, not an error).

### 3.2 INSERT Style Options

| `DataStyle` | Rendered output | Notes |
|-------------|-----------------|-------|
| `Insert` | `INSERT INTO t (cols) VALUES (r1),(r2),...` | Standard multi-row INSERT; default |
| `InsertUpdate` | MySQL: `INSERT INTO t ... ON DUPLICATE KEY UPDATE col=VALUES(col),...`; PgSQL/SQLite: `INSERT OR REPLACE INTO t ...`; MSSQL/Oracle: `MERGE` | Capability-gated: requires `Capability::InsertUpdate`; falls back to plain INSERT with a warning event on unsupported engines |
| `TruncateInsert` | `TRUNCATE TABLE t;` before the INSERTs | SQL writer emits the TRUNCATE as table DDL (not as a data row); the data rows still use plain INSERT |

**NULL representation in SQL:** `NULL` keyword is never quoted. Column values that are PHP `null` emit the `NULL` keyword; empty strings emit `''`. Binary-encoded columns go through `QuotingInterface::quoteBinary()` per the platform's convention (MySQL: `0x...`; PgSQL: `E'\\x...'`; SQLite: `X'...'`).

### 3.3 DELIMITER Handling for Triggers and Routines

MySQL requires a DELIMITER change to include trigger/routine bodies that contain semicolons:

```sql
DELIMITER $$
CREATE TRIGGER before_order_insert BEFORE INSERT ON orders FOR EACH ROW
BEGIN
  SET NEW.created_at = NOW();
END$$
DELIMITER ;
```

`SqlFormatWriter::writeTriggersForTable()` wraps each trigger in a `DELIMITER $$` / `DELIMITER ;` block when targeting MySQL/MariaDB. On other engines (PgSQL: function body uses `$$` quoting, no DELIMITER directive needed at the client level; SQLite: no triggers in dump for SQLite since triggers are natively supported but SQLite's dump format is different) the wrapping is omitted. The `StatementSplitter` on import (§5) recognizes these DELIMITER blocks.

---

## 4. CSV / TSV Format Writer

### 4.1 Behavior

`CsvFormatWriter` and `TsvFormatWriter` share an abstract base. Key behavior:

- **Header row:** first row is column names. Always included; not configurable in v1 (Adminer always includes headers; there is no evident consumer need to omit them).
- **Separator:** CSV uses `,`, `CsvSemicolonFormatWriter` uses `;` (European locale preference matching Adminer's two variants), TSV uses `\t`.
- **Quoting:** RFC 4180 quoting — each field is double-quoted if it contains the separator, a double-quote, or a newline. All other fields are unquoted (Adminer's behavior). The escaping character is `""` (double-double-quote), not backslash.
- **NULL representation:** configurable via `DumpOptions::$nullRepresentation`; default is `\N` (MySQL convention), which is distinct from an empty string.
- **Line ending:** `\r\n` (RFC 4180 standard). Configurable to `\n` only if the consumer sets it explicitly via a format writer option.
- **Encoding:** UTF-8 always. No BOM. Cross-engine character set conversion is not SQLCraft's job; the connection charset (set at connection open time) determines what the DB returns.
- **Binary columns:** emitted as base64-encoded strings (the hex representation used for SQL is unreadable in a spreadsheet context; base64 is a reasonable compact representation and reversible).
- **Streaming:** one row at a time via the streaming result from `ConnectionInterface::query($sql, streaming: true)`. No in-memory accumulation. The `writeRows()` callback receives batches of `$batchSize` rows but each batch is written immediately to the sink.

### 4.2 DDL in CSV/TSV

CSV and TSV formats have no concept of DDL. `writeTableDdl()` is a no-op for CSV/TSV writers. `writeHeader()`/`writeFooter()` also no-op. Only `writeRows()` produces output. This means a CSV export of a multi-table scope produces one logical CSV blob per table (or, with `MultiFileSink`, one file per table — the natural usage for CSV multi-table exports).

---

## 5. JSON / XML Format Writers

### 5.1 JSON

`JsonFormatWriter` emits a streaming JSON structure without buffering the whole document:

```json
{
  "tables": [
    {
      "name": "orders",
      "columns": ["id", "customer_id", "total", "created_at"],
      "rows": [
        [1, 42, "199.99", "2025-01-15T12:00:00Z"],
        [2, 43, "59.00",  "2025-01-15T13:00:00Z"]
      ]
    }
  ]
}
```

Rows are emitted as arrays (positional) rather than objects (keyed) because column names in the header eliminate redundancy and arrays are 20–40% smaller for typical row data. A `rowsAsObjects` option (default `false`) switches to `{"id":1,"customer_id":42,...}` per row — useful when the consumer wants self-describing rows.

Streaming implementation: the writer opens the root object and the `tables` array on `writeHeader()`; opens each table object on `writeTableHeader()`; emits the `columns` array and begins the `rows` array on `writeTableDdl()`/first `writeRows()`; closes the `rows` array and table object on `writeTableFooter()`; closes the outer structure on `writeFooter()`. Each write is immediately flushed to the sink — there is no deferred rendering.

No DDL in JSON format (structural metadata is expressed as the `columns` array, not SQL).

### 5.2 XML

`XmlFormatWriter` emits well-formed XML:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<dump>
  <table name="orders">
    <row>
      <id>1</id>
      <customer_id>42</customer_id>
      <total>199.99</total>
    </row>
  </table>
</dump>
```

Column names become element names; values are text content; special characters are escaped (`htmlspecialchars()`). Binary fields are base64-encoded with a `type="binary"` attribute. NULL values emit an empty element with a `null="true"` attribute. Streaming: same incremental-write pattern as JSON.

---

## 6. Import Architecture

### 6.1 Source Abstraction

```php
namespace SQLCraft\Contracts\Import;

interface ImportSourceInterface
{
    /**
     * Return a PHP readable stream resource for the import content.
     * The implementation handles decompression transparently:
     * - .sql.gz → decompress via zlib stream filter before returning
     * - .sql.bz2 → decompress via bz2 stream filter
     * - plain .sql → return handle as-is
     * Throws ExtensionMissingException if decompression is needed but the extension is unavailable.
     */
    public function openStream(): mixed; // returns a stream resource (not typed beyond resource in PHP 8.4)

    /** File size hint in bytes, null if unknown (e.g., piped input). Used only for progress estimation. */
    public function getEstimatedSize(): ?int;
}
```

**Built-in sources:**

| Class | Notes |
|-------|-------|
| `FileImportSource` | Reads from an absolute file path; handles `.gz`/`.bz2` extension detection automatically |
| `StreamImportSource` | Wraps an already-open PHP stream resource (e.g., a PSR-7 uploaded file's detached stream) |
| `StringImportSource` | Wraps an in-memory string via `fopen('php://memory', 'r+')` + `fwrite`; for small imports in tests |
| `Psr7StreamImportSource` | Wraps a PSR-7 `StreamInterface` (e.g., `$request->getUploadedFiles()['sql_file']->getStream()`) |

Gzip detection: `FileImportSource` checks the first two bytes for the gzip magic `\x1f\x8b` rather than relying on the file extension alone (matching what Adminer does for transparent `.sql.gz` import). If detected and `ext-zlib` is unavailable, `ExtensionMissingException` is thrown immediately rather than silently importing compressed bytes as text.

### 6.2 `StatementSplitter` — State Machine

Adminer splits SQL by reading 100KB chunks and scanning for delimiters, with a simplified parser that handles quoted strings. This works for typical cases but has known edge cases at chunk boundaries: a delimiter that falls exactly on a 100KB boundary can be split, causing the next chunk to start with `; DROP TABLE` which either fails or, if it happens to be a valid partial statement, is silently wrong.

SQLCraft replaces this with a proper character-by-character state machine that reads from a stream (not fixed-size chunks) and emits complete statements:

```php
namespace SQLCraft\Contracts\Import;

use SQLCraft\Execution\StatementBatch;

interface StatementSplitterInterface
{
    /**
     * Read from $stream and yield complete SQL statements one by one.
     * Respects DELIMITER directives (MySQL-style: "DELIMITER $$").
     * Handles:
     *   - Single-quoted strings ('...' with '' escaping and \' optional)
     *   - Double-quoted identifiers ("...") and strings (MySQL ANSI_QUOTES-compatible)
     *   - Backtick-quoted identifiers (`...`)
     *   - Block comments (/* ... *\/)
     *   - Line comments (-- ... and # ...)
     *   - Dollar-quoted strings (PgSQL: $tag$...$tag$, $$...$$)
     *
     * @param resource $stream
     * @return \Generator<string>  yields one complete SQL statement per iteration
     */
    public function split(mixed $stream, string $delimiter = ';'): \Generator;
}
```

**State machine states:**

| State | Transition in | Transition out |
|-------|--------------|----------------|
| `NORMAL` | initial / end of string/comment | Start of string delimiter → `IN_STRING`; `--` / `#` → `LINE_COMMENT`; `/*` → `BLOCK_COMMENT`; `$$` or user-defined delimiter → `IN_DOLLAR_QUOTE`; statement delimiter found → yield statement |
| `IN_SINGLE_QUOTE` | `'` in NORMAL | Closing unescaped `'` → NORMAL; `''` = escaped quote, stays in state |
| `IN_DOUBLE_QUOTE` | `"` in NORMAL | Closing unescaped `"` → NORMAL |
| `IN_BACKTICK` | `` ` `` in NORMAL | Closing `` ` `` → NORMAL |
| `IN_DOLLAR_QUOTE` | `$tag$` in NORMAL | Matching closing `$tag$` → NORMAL |
| `LINE_COMMENT` | `--` or `#` in NORMAL | Newline `\n` → NORMAL |
| `BLOCK_COMMENT` | `/*` in NORMAL | `*/` → NORMAL |
| `DELIMITER_DIRECTIVE` | `DELIMITER` keyword at start of statement in NORMAL | End of line → NORMAL (new delimiter is stored) |

This approach is O(n) in input length and O(1) in memory beyond the current statement buffer (max buffer = size of one statement, not the full file). It reads from the stream in configurable chunks (default 8KB, not Adminer's 100KB) to balance I/O calls against memory pressure, but chunk boundaries never affect correctness because the state machine tracks position within each chunk before requesting the next.

**DELIMITER directive handling:** `DELIMITER $$` lines are recognized when they appear as the first non-whitespace token of a new statement. The `$$` (or whatever string follows) becomes the new active delimiter. `DELIMITER ;` resets to the default. The DELIMITER line itself is consumed and not yielded as a statement (matching Adminer: the DB never sees DELIMITER directives). This allows importing MySQL routine/trigger definition files verbatim.

### 6.3 `Importer` — SQL Import Orchestration

```php
namespace SQLCraft\Contracts\Import;

interface ImporterInterface
{
    /**
     * Import SQL statements from $source into $conn.
     * Fires ImportStartedEvent / ImportProgressEvent (every $progressInterval statements) /
     * ImportFinishedEvent or ImportFailedEvent (16-events.md §5.5).
     *
     * @param ImportOptions $options controls stop-on-error, transaction wrapping, progress interval
     * @return ImportResult summary of statements run and errors collected
     */
    public function import(
        ConnectionInterface   $conn,
        ImportSourceInterface $source,
        ImportOptions         $options,
    ): ImportResult;
}
```

```php
final readonly class ImportOptions
{
    public function __construct(
        public readonly bool   $stopOnError       = true,
        public readonly bool   $wrapInTransaction = false,  // PgSQL/SQLite support transactional DDL; MySQL does NOT
        public readonly int    $progressInterval  = 50,     // fire ImportProgressEvent every N statements
        public readonly int    $statementTimeoutMs = 0,     // 0 = no per-statement timeout
        public readonly ?int   $maxStatements     = null,   // safety cap; null = unlimited
    ) {}
}

final readonly class ImportResult
{
    /** @param list<ImportError> $errors */
    public function __construct(
        public readonly int   $statementsExecuted,
        public readonly int   $statementsSkipped,   // stopped due to error in stop-on-error mode
        public readonly array $errors,
        public readonly float $elapsedMs,
    ) {}
}

final readonly class ImportError
{
    public function __construct(
        public readonly int    $statementIndex,
        public readonly string $sql,
        public readonly string $errorMessage,
        public readonly int    $errorCode,
    ) {}
}
```

**Transaction wrapping caveat:** `ImportOptions::$wrapInTransaction = true` wraps the entire import in a `TransactionManager::transactional()` block (12-query-engine.md §5). For MySQL this is intentionally `false` by default because MySQL auto-commits DDL (`CREATE TABLE`, `ALTER TABLE`, etc.) regardless of transaction state — wrapping a MySQL import in a transaction gives a false sense of atomicity. For PgSQL and SQLite (which support transactional DDL) `true` provides genuine all-or-nothing semantics. The importer does not validate whether transaction wrapping is safe for the current engine; that decision is left to the caller who knows their import content. A future `SafeImportOptions::forEngine(string $engine)` factory method could provide sensible defaults per engine.

### 6.4 CSV Import

CSV import is a separate code path from SQL import: there is no statement splitting involved. The `CsvImporter` reads the source as a CSV stream (using the same `ImportSourceInterface` and its stream resource), maps header columns to table columns, and runs batched INSERT/UPSERT statements.

```php
namespace SQLCraft\Contracts\Import;

interface CsvImporterInterface
{
    /**
     * Import CSV data from $source into $table.
     * First row must be a header row of column names.
     * Columns not present in the source are left at their DB default.
     * Unknown source columns (header names that don't match any table column) are ignored with a warning event.
     *
     * @param UpsertMode $upsertMode Insert / InsertOrIgnore / InsertOrReplace (platform-mapped)
     */
    public function importCsv(
        ConnectionInterface   $conn,
        QualifiedName         $table,
        ImportSourceInterface $source,
        CsvImportOptions      $options,
    ): ImportResult;
}
```

```php
final readonly class CsvImportOptions
{
    public function __construct(
        public readonly string $separator           = ',',
        public readonly string $nullRepresentation  = '\\N',
        public readonly UpsertMode $upsertMode      = UpsertMode::Insert,
        public readonly bool   $wrapInTransaction   = true,
        public readonly int    $batchSize           = 100,
        public readonly int    $statementTimeoutMs  = 0,
    ) {}
}

enum UpsertMode { case Insert; case InsertOrIgnore; case InsertOrReplace; }
```

The CSV importer uses `PreparedStatementInterface` (10-connection-layer.md §11) for batch execution — one prepared statement per batch, executed once per `$batchSize` rows. This amortizes the per-statement prepare overhead for large CSV imports.

---

## 7. Format Extension Registry

Consumers can register custom format writers and readers. The built-in formats are pre-registered; third-party formats are registered at DI bootstrap time.

```php
namespace SQLCraft\Export;

final class FormatRegistry
{
    /** @var array<string, FormatWriterInterface> */
    private array $writers = [];

    /** @var array<string, FormatReaderInterface> */
    private array $readers = [];

    public function registerWriter(FormatWriterInterface $writer): void
    {
        $this->writers[$writer->getFormatName()] = $writer;
    }

    public function registerReader(FormatReaderInterface $reader): void
    {
        $this->readers[$reader->getFormatName()] = $reader;
    }

    public function getWriter(string $format): FormatWriterInterface
    {
        return $this->writers[$format] ?? throw FormatNotFoundException::forWriter($format);
    }

    /** @return list<string> */
    public function getSupportedWriteFormats(): array { return array_keys($this->writers); }
    public function getSupportedReadFormats(): array  { return array_keys($this->readers); }
}
```

```php
namespace SQLCraft\Contracts\Import;

/** Reader-side counterpart to FormatWriterInterface, for importable formats. */
interface FormatReaderInterface
{
    public function getFormatName(): string;

    /**
     * Read records from $source and yield them as associative arrays.
     * The format reader is responsible for header detection, encoding, etc.
     * @return \Generator<array<string, mixed>>
     */
    public function readRows(mixed $stream, FormatReadOptions $options): \Generator;
}
```

**Example — a custom XLSX writer:**

```php
// Third-party extension — not shipped with SQLCraft core
namespace Acme\SQLCraft\Excel;

use SQLCraft\Contracts\Export\{FormatWriterInterface, SinkInterface};
use SQLCraft\Export\DumpOptions;
use SQLCraft\DTO\{TableStatus, ColumnMeta};

final class XlsxFormatWriter implements FormatWriterInterface
{
    public function getFormatName(): string { return 'xlsx'; }

    public function writeHeader(SinkInterface $sink, DumpOptions $options): void
    {
        $this->workbook = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    }

    public function writeRows(SinkInterface $sink, TableStatus $table, array $rows, array $columns, DumpOptions $options): void
    {
        // Fill spreadsheet sheet in memory... batch by batch
    }

    public function writeFooter(SinkInterface $sink, DumpOptions $options): void
    {
        // Write the completed spreadsheet to the sink as xlsx bytes
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($this->workbook);
        // ... serialize to temp buffer, then $sink->write(...)
    }
    // ... other lifecycle methods
}

// Registration at application bootstrap:
$registry->registerWriter(new XlsxFormatWriter());
```

**Note:** XLSX as an export format inherently buffers the whole output in memory (a spreadsheet cannot be streamed before it is complete). The `FormatWriterInterface` contract does not require streaming — it allows buffering. Callers using XLSX must be aware of the memory implications for large exports, but the interface does not artificially prevent it. The `StringBufferSink` is the appropriate sink for XLSX export, with the consumer transferring the accumulated bytes to HTTP response or disk after export completes.

---

## 8. Resource Limits and Cancellation

### 8.1 Import Limits

| Limit | Mechanism | Default |
|-------|-----------|---------|
| Max file size | Checked by `ImportSourceInterface::getEstimatedSize()` against a configurable cap; throws `ImportFileTooLargeException` before reading begins | No default limit; consumer configures in `ImportOptions` |
| Max statement count | `ImportOptions::$maxStatements`; iteration stops when reached, yielding a partial `ImportResult` | Unlimited by default |
| Per-statement timeout | `ImportOptions::$statementTimeoutMs`; each statement wrapped in `QueryExecutor::queryWithTimeout()` (12-query-engine.md §10) | 0 (disabled) by default |
| Memory bounds | Guaranteed by streaming architecture: statement buffer = one statement, row buffer = `$batchSize` rows | Inherent, not separately configurable |

### 8.2 Export Limits

No hard row-count limit is imposed by default on export — the consumer has explicitly requested the export and presumably knows the size. However, the export path is entirely streaming; any `SinkInterface` can stop accepting writes (close early) and the generator loop on the export side will stop when it can no longer write. This provides a natural back-pressure mechanism for HTTP streaming scenarios.

### 8.3 Cancellation

Long-running imports can be cancelled by registering a `BeforeQueryExecuted` interception listener (16-events.md §4.2) that checks a shared cancellation flag and calls `$event->cancel()`. The `BatchExecutor`'s `executeBatch()` generator (12-query-engine.md §4.2) propagates the `OperationCancelledException` out of the `foreach`, and the `Importer` catches it, sets `ImportResult::$cancelled = true`, and returns a partial result. No special cancellation API is needed in the Importer itself — the event system already provides the hook.

---

## 9. Contrast with Adminer

| Concern | Adminer | SQLCraft |
|---------|---------|----------|
| Output target | Direct `echo`/`ob_start()` → `php://output`; HTTP headers set inline | Injectable `SinkInterface`; consumer decides where bytes go |
| Memory usage | Entire result set may buffer via `ob_start()` at some code paths | Streaming row generator throughout; max in-memory = one batch (`$batchSize` rows) |
| Gzip handling | Sets HTTP `Content-Encoding: gzip` header; `ob_gz_handler` | `GzipSink` decorator; no HTTP assumptions; `ext-zlib` check at construction |
| Statement splitting | 100KB fixed chunk + simple delimiter scan (chunk-boundary edge cases) | Proper state-machine splitter; reads in 8KB chunks but chunk boundaries never affect parser correctness |
| Format plugins | `dumpFormat()` append hook via `__call` magic; array union return | `FormatWriterInterface` + `FormatRegistry::registerWriter()`; fully typed; IDE-navigable |
| Import transaction wrap | Optional, implicit | Explicit `ImportOptions::$wrapInTransaction`; documented per-engine caveat |
| Progress reporting | None (web HTTP response is the only output; no progress API) | `ImportProgressEvent` / `ExportProgressEvent` via PSR-14 dispatcher (16-events.md §5.5); consumer registers a listener |
| CSV import mapping | Headers matched by name (Adminer) | Same; unmapped source columns fire a warning event rather than silently dropping |
| Multi-format support | SQL, CSV (comma), CSV (semicolon), TSV; plugins add more | Same built-in set + typed `FormatWriterInterface` / `FormatReaderInterface` extension registry |
| Statement timeout per import statement | Not supported | `ImportOptions::$statementTimeoutMs` per statement via `QueryExecutor::queryWithTimeout()` |

---

## 10. Summary of Key Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Output sink | `SinkInterface` abstraction, never `echo`/`php://output` directly | Library must not assume an HTTP response context |
| Streaming | Row generator throughout export; 8KB read chunks on import | O(1) memory regardless of dataset size |
| Statement splitter | State machine, not fixed-chunk scan | Eliminates chunk-boundary edge cases; correct for all valid SQL including DELIMITER directives |
| Format extension | `FormatWriterInterface` + `FormatRegistry` | Typed, discoverable, IDE-friendly; replaces Adminer's `__call`-based `dumpFormat` append hook |
| GZip | `GzipSink` decorator + magic-byte detection on import | Clean separation; graceful degradation with explicit `ExtensionMissingException` if ext-zlib absent |
| Batch size | 100 rows per batch (same as Adminer) | Preserves proven behavioral characteristic; configurable via `DumpOptions::$batchSize` |
| PgSQL FK ordering | Defer all FK `ALTER TABLE` statements after all `CREATE TABLE` | Avoids FK dependency ordering issues including circular references; mirrors Adminer's PgSQL driver behavior |
| Archive output | Not provided; `MultiFileSink` + consumer-chosen archiver | Keeps SQLCraft dependency-free for archive formats; archive packaging is trivial glue code |
| Cancellation | Via `BeforeQueryExecuted` interception event | No new API needed; reuses the event system already designed in 16-events.md |
| CSV null representation | `\N` by default (MySQL convention), configurable | Distinguishes NULL from empty string in all contexts; same default as Adminer |

