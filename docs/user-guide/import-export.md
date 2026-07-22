# Import and Export

SQLCraft provides a streaming, event-driven pipeline for exporting database data to SQL, CSV, and TSV formats and importing it back. Neither the exporter nor the importer loads entire tables into memory — data flows in configurable batches.

---

## Overview

```php
$exporter = $db->export(); // Exporter
$importer = $db->import(); // Importer
```

Both are wired automatically by `SQLCraftFactory`. The exporter writes to a `SinkInterface`; the importer reads from an `ImportSourceInterface`. Both fire PSR-14 events at start, progress intervals, and completion.

---

## Exporter

### Supported Formats

| Format name | Class | Description |
|---|---|---|
| `sql` | `SqlFormatWriter` | `INSERT` statements with `CREATE TABLE` DDL |
| `csv` | `CsvFormatWriter` | RFC 4180 CSV with comma separator |
| `tsv` | `TsvFormatWriter` | Tab-separated values |
| `csv-semicolon` | `CsvSemicolonFormatWriter` | CSV with semicolon separator (European locale) |

Use `FormatRegistry::getSupportedWriteFormats()` to enumerate available formats at runtime.

### `DumpScope` — What to Export

`DumpScope` is a sealed value object with four static constructors:

```php
use SQLCraft\Export\DumpScope;

// Entire current database
$scope = DumpScope::database('myapp');

// Specific tables only
$scope = DumpScope::tables('myapp', ['orders', 'order_items']);

// Single table shorthand
$scope = DumpScope::table('myapp', 'orders');

// A filtered result set (e.g., export rows matching a WHERE clause)
$scope = DumpScope::filteredResult(
    database: 'myapp',
    table: 'orders',
    sql: 'SELECT * FROM orders WHERE status = \'pending\'',
);

// All databases (multi-database dump)
$scope = DumpScope::allDatabases();
```

### `DumpOptions`

`DumpOptions` controls format, scope, and SQL/CSV generation details.

```php
use SQLCraft\Export\DumpOptions;
use SQLCraft\Export\DumpScope;
use SQLCraft\Export\TableSectionStyle;
use SQLCraft\Export\DataStyle;
use SQLCraft\Export\DatabaseSectionStyle;

$options = new DumpOptions(
    format: 'sql',
    scope: DumpScope::database('myapp'),
    databaseStyle: DatabaseSectionStyle::None,      // or Create / UseStatement
    tableStyle: TableSectionStyle::DropCreate,       // or Create / None
    dataStyle: DataStyle::Insert,                    // or InsertUpdate / None
    includeAutoIncrement: true,
    includeTriggers: false,
    includeRoutines: false,
    includeEvents: false,
    batchSize: 100,                                  // rows per INSERT batch
    csvSeparator: null,                              // overrides format default
    nullRepresentation: '\\N',                       // CSV null representation
);
```

### Sinks

A `SinkInterface` is the destination for exported bytes. SQLCraft ships several implementations:

| Class | Description |
|---|---|
| `ResourceSink` | Wraps a PHP stream resource (`fopen`, `php://stdout`) |
| `StringBufferSink` | Accumulates output in a string (testing / small exports) |
| `GzipSink` | Wraps another sink; compresses output with gzip |
| `Bzip2Sink` | Wraps another sink; compresses with bzip2 |
| `Psr7StreamSink` | Wraps a PSR-7 `StreamInterface` |
| `MultiFileSink` | Splits output across multiple files |

### Export to a File

```php
use SQLCraft\Export\DumpOptions;
use SQLCraft\Export\DumpScope;
use SQLCraft\Export\ResourceSink;

$sink = new ResourceSink(fopen('/tmp/myapp.sql', 'wb'));

$db->export()->export(
    $db->connection(),
    $sink,
    new DumpOptions(
        format: 'sql',
        scope: DumpScope::database('myapp'),
    ),
);

fclose($sink->resource());
```

### Export to a Gzip-Compressed File

```php
use SQLCraft\Export\GzipSink;
use SQLCraft\Export\ResourceSink;

$inner = new ResourceSink(fopen('/tmp/myapp.sql.gz', 'wb'));
$sink  = new GzipSink($inner);

$db->export()->export($db->connection(), $sink, $options);
```

### Export to a String Buffer

Useful in tests or when you need the SQL as a string:

```php
use SQLCraft\Export\StringBufferSink;

$sink = new StringBufferSink();
$db->export()->export($db->connection(), $sink, $options);
$sql = $sink->getContents();
```

### Export Specific Tables as CSV

```php
$options = new DumpOptions(
    format: 'csv',
    scope: DumpScope::tables('myapp', ['products', 'categories']),
    batchSize: 500,
    nullRepresentation: '',
);

$sink = new ResourceSink(fopen('/tmp/products.csv', 'wb'));
$db->export()->export($db->connection(), $sink, $options);
```

### Export a Filtered Result Set

Useful for exporting only rows matching a condition — for example, backing up a user's data:

```php
$options = new DumpOptions(
    format: 'sql',
    scope: DumpScope::filteredResult(
        'myapp',
        'orders',
        "SELECT * FROM orders WHERE user_id = 42",
    ),
);
$db->export()->export($db->connection(), $sink, $options);
```

### Streaming Architecture

The exporter fetches data in `batchSize` rows per query using `LIMIT`/`OFFSET`. Each batch is written to the sink immediately. Memory usage stays proportional to `batchSize` regardless of table size.

Foreign-key-aware topological sorting is applied to table order in database-scope exports: parent tables are emitted before child tables to ensure correct import order. If a foreign-key cycle is detected, a warning event is fired and declaration order is preserved.

---

## Export Events

Listen to these PSR-14 events for progress tracking and logging:

| Event | Fired when |
|---|---|
| `ExportStartedEvent` | Export begins |
| `ExportProgressEvent` | After each table is exported |
| `ExportFinishedEvent` | Export completes successfully |
| `ExportWarningEvent` | Non-fatal issues (cycles, deferred features) |

```php
use SQLCraft\Events\ExportProgressEvent;
use SQLCraft\Events\ExportFinishedEvent;

$dispatcher->addListener(ExportProgressEvent::class, function (ExportProgressEvent $e): void {
    printf("Exported %d tables, %d rows (%.1f ms elapsed)\n",
        $e->tablesExported, $e->rowsExported, $e->elapsedMs);
});

$dispatcher->addListener(ExportFinishedEvent::class, function (ExportFinishedEvent $e): void {
    printf("Done: %d tables, %d rows in %.1f ms\n",
        $e->tablesExported, $e->rowsExported, $e->elapsedMs);
});
```

---

## Round-Trip Guarantee

SQL exports produced by `SqlFormatWriter` are designed to be importable back into the same platform without modification. The export includes:

- `DROP TABLE IF EXISTS` (when `TableSectionStyle::DropCreate`)
- `CREATE TABLE` with full column definitions
- `INSERT` statements in `batchSize` rows per statement
- Trigger definitions when `includeTriggers: true`
- Routine definitions when `includeRoutines: true`

To verify round-trip fidelity in tests:

```php
// Export
$sink = new StringBufferSink();
$db->export()->export($conn, $sink, new DumpOptions('sql', DumpScope::database('test_db')));

// Wipe and reimport
$db->connection()->execute('DROP DATABASE test_db');
$db->connection()->execute('CREATE DATABASE test_db');

$stream = fopen('php://memory', 'r+');
fwrite($stream, $sink->getContents());
rewind($stream);

$result = $db->import()->import(
    $conn,
    new \SQLCraft\Import\StreamImportSource($stream),
    new \SQLCraft\Import\ImportOptions(),
);
assert($result->statementsExecuted > 0);
assert($result->errors === []);
```

---

## Importer

### `ImportOptions`

```php
use SQLCraft\Import\ImportOptions;

$options = new ImportOptions(
    stopOnError: true,        // abort on first error
    wrapInTransaction: false, // wrap all statements in a transaction
    progressInterval: 50,     // fire ImportProgressEvent every N statements
    statementTimeoutMs: 0,    // per-statement timeout (0 = no limit)
    maxStatements: null,      // cap total statements executed
);
```

### Import Sources

An `ImportSourceInterface` provides a stream and optional size hint. Use the provided implementations:

```php
use SQLCraft\Import\FileImportSource;
use SQLCraft\Import\StreamImportSource;

// From a file path
$source = new FileImportSource('/path/to/dump.sql');

// From any PHP stream resource
$stream = fopen('/path/to/dump.sql', 'rb');
$source = new StreamImportSource($stream);

// Gzip-compressed file: decompress on the fly
$stream = gzopen('/path/to/dump.sql.gz', 'rb');
$source = new StreamImportSource($stream, estimatedSize: filesize('/path/to/dump.sql.gz'));
```

### Running an Import

```php
use SQLCraft\Import\ImportOptions;
use SQLCraft\Import\FileImportSource;

$result = $db->import()->import(
    $db->connection(),
    new FileImportSource('/tmp/myapp.sql'),
    new ImportOptions(
        stopOnError: false,
        wrapInTransaction: true,
        progressInterval: 100,
    ),
);

echo "Executed: {$result->statementsExecuted}\n";
echo "Skipped:  {$result->statementsSkipped}\n";
echo "Elapsed:  {$result->elapsedMs} ms\n";

foreach ($result->errors as $error) {
    printf("Error at statement %d: %s\n  SQL: %s\n",
        $error->statementIndex,
        $error->errorMessage,
        substr($error->sql, 0, 80),
    );
}
```

### `ImportResult` fields

| Field | Type | Description |
|---|---|---|
| `statementsExecuted` | `int` | Successfully executed statement count |
| `statementsSkipped` | `int` | Statements skipped due to errors |
| `errors` | `list<ImportError>` | Per-statement error details |
| `elapsedMs` | `float` | Total import duration in milliseconds |

### `ImportError` fields

| Field | Type | Description |
|---|---|---|
| `statementIndex` | `int` | Zero-based index in the script |
| `sql` | `string` | The statement that failed |
| `errorMessage` | `string` | Exception message |
| `errorCode` | `int` | PDO/driver error code |

---

## `StatementSplitter` and DELIMITER Handling

The importer uses `StatementSplitter` internally. When a SQL script contains stored procedures or triggers that include semicolons in their body, the `DELIMITER` directive must be used:

```sql
CREATE TABLE users (id INT, name VARCHAR(100));

DELIMITER $$

CREATE PROCEDURE greet(IN name VARCHAR(100))
BEGIN
    SELECT CONCAT('Hello, ', name);
END$$

DELIMITER ;

INSERT INTO users VALUES (1, 'Alice');
```

`StatementSplitter` tracks the current delimiter, splits statements correctly, and strips the `DELIMITER` directives from the output. The above script produces three statements: `CREATE TABLE`, `CREATE PROCEDURE`, and `INSERT`.

For large files, `StatementSplitter` implements `StreamingStatementSplitterInterface` and processes the stream line by line:

```php
use SQLCraft\Query\StatementSplitter;

$splitter = new StatementSplitter();
$stream   = fopen('/large/dump.sql', 'rb');

foreach ($splitter->splitStream($stream) as $statement) {
    // Each statement is yielded as it is parsed — no full load into memory
    $db->connection()->execute($statement);
}
```

---

## Import Events

| Event | Fired when |
|---|---|
| `ImportStartedEvent` | Import begins |
| `ImportProgressEvent` | Every `progressInterval` statements |
| `ImportFinishedEvent` | Import completes successfully |
| `ImportFailedEvent` | An unrecoverable error occurs |

```php
use SQLCraft\Events\ImportProgressEvent;
use SQLCraft\Events\ImportFailedEvent;

$dispatcher->addListener(ImportProgressEvent::class, function (ImportProgressEvent $e): void {
    printf("Imported %d statements (%.1f ms)\n", $e->statementsExecuted, $e->elapsedMs);
});

$dispatcher->addListener(ImportFailedEvent::class, function (ImportFailedEvent $e): void {
    $logger->error('Import failed', [
        'error' => $e->error->getMessage(),
        'last_sql' => $e->lastSql,
    ]);
});
```

---

## CSV Import

`FormatRegistry` ships with `CsvFormatReader` for importing CSV files. CSV import infers column order from the header row.

```php
use SQLCraft\Import\CsvImportSource;
use SQLCraft\Import\ImportOptions;

$source = new CsvImportSource(
    path: '/tmp/users.csv',
    table: 'users',
    separator: ',',
    enclosure: '"',
    hasHeader: true,
    nullValue: '',           // empty string becomes NULL
    encoding: 'UTF-8',
);

$result = $db->import()->import(
    $db->connection(),
    $source,
    new ImportOptions(stopOnError: false),
);
```

### CSV Coercion Policy

| CSV value | `nullValue = ''` | `nullValue = '\\N'` |
|---|---|---|
| (empty cell) | `NULL` | `''` (empty string) |
| `\N` | `\N` (string) | `NULL` |
| `2024-01-15` | parsed as string | parsed as string |
| `42` | `42` (string; driver may cast) | `42` |

SQLCraft does not coerce CSV strings to typed PHP values by default. Type coercion is the responsibility of the database engine through column type information.

---

## `FormatRegistry`

`SQLCraftFactory` pre-registers all built-in writers and readers. Register additional formats at construction time:

```php
use SQLCraft\Export\FormatRegistry;
use SQLCraft\Export\SqlFormatWriter;
use SQLCraft\Export\CsvFormatWriter;
use SQLCraft\Export\TsvFormatWriter;
use SQLCraft\Export\CsvSemicolonFormatWriter;
use SQLCraft\Import\CsvFormatReader;

$registry = new FormatRegistry(
    writers: [
        new SqlFormatWriter($connection),
        new CsvFormatWriter(),
        new TsvFormatWriter(),
        new CsvSemicolonFormatWriter(),
    ],
    readers: [
        new CsvFormatReader(),
    ],
);

// Enumerate available formats
$registry->getSupportedWriteFormats(); // ['sql', 'csv', 'tsv', 'csv-semicolon']
$registry->getSupportedReadFormats();  // ['csv']
```

Implement `FormatWriterInterface` to add custom formats (e.g., Excel XLSX, JSON Lines):

```php
class JsonLinesFormatWriter implements \SQLCraft\Contracts\Export\FormatWriterInterface
{
    public function getFormatName(): string { return 'jsonl'; }
    // implement writeHeader, writeRow, writeFooter, writeTableHeader, writeTableFooter
}

$registry->registerWriter(new JsonLinesFormatWriter());
```

---

## Large File Handling

### Export

- Set `batchSize` to a value that fits comfortably in available memory (100–500 rows for wide tables with large TEXT/BLOB columns; 1000–5000 for narrow tables).
- Use `GzipSink` or `Bzip2Sink` to reduce disk I/O for large dumps.
- For very large databases, use `DumpScope::tables()` to export in logical groups rather than a single monolithic dump.

### Import

- Use `FileImportSource` or a stream-based source — never `file_get_contents()` for files over a few MB.
- Set `wrapInTransaction: true` only when the entire import must succeed atomically; for very large imports, a mid-import rollback can be expensive.
- Use `stopOnError: false` with `progressInterval` tuned to emit frequent enough progress events for UI feedback.
- Set `statementTimeoutMs` to a non-zero value in environments where a single runaway statement could block the import indefinitely.
- Tune `maxStatements` to run partial imports during development.

```php
// Development: import only first 500 statements
$options = new ImportOptions(
    stopOnError: false,
    maxStatements: 500,
    progressInterval: 10,
);
```

---

## Platform-Specific Notes

### MySQL

- SQL exports use `/*!40000 ALTER TABLE ... DISABLE KEYS */` blocks to speed up bulk imports when `tableStyle` is `DropCreate`.
- Triggers are exported with `DELIMITER $$` guards automatically.
- `SET FOREIGN_KEY_CHECKS = 0` is emitted at the start of database-scope exports to allow import in any table order.

### PostgreSQL

- Sequences are exported as `CREATE SEQUENCE` + `SELECT setval(...)` when `includeAutoIncrement: true`.
- Materialized view export is not currently included in standard database-scope exports.
- The `COPY` protocol is not used; exports use standard `INSERT` for portability.

### SQLite

- `BEGIN TRANSACTION` / `COMMIT` are emitted around data sections in SQL exports for performance.
- `ATTACH DATABASE` is not supported by the exporter; multi-database exports are not available.

### SQL Server

- Identity insert handling (`SET IDENTITY_INSERT ... ON/OFF`) is emitted automatically around `INSERT` blocks for tables with identity columns.

---

## Complete Example: Backup and Restore

```php
use SQLCraft\Export\DumpOptions;
use SQLCraft\Export\DumpScope;
use SQLCraft\Export\GzipSink;
use SQLCraft\Export\ResourceSink;
use SQLCraft\Import\FileImportSource;
use SQLCraft\Import\ImportOptions;

$backupPath = '/backups/myapp-' . date('Ymd-His') . '.sql.gz';

// --- Export ---
$inner = new ResourceSink(fopen($backupPath, 'wb'));
$sink  = new GzipSink($inner);

$db->export()->export(
    $db->connection(),
    $sink,
    new DumpOptions(
        format: 'sql',
        scope: DumpScope::database('myapp'),
        tableStyle: TableSectionStyle::DropCreate,
        dataStyle: DataStyle::Insert,
        includeAutoIncrement: true,
        includeTriggers: true,
        batchSize: 200,
    ),
);

// --- Restore ---
$stream = gzopen($backupPath, 'rb');

$result = $db->import()->import(
    $db->connection(),
    new \SQLCraft\Import\StreamImportSource($stream),
    new ImportOptions(
        stopOnError: true,
        wrapInTransaction: false, // DDL statements are not transactional on MySQL
        progressInterval: 500,
    ),
);

if ($result->errors !== []) {
    throw new \RuntimeException(
        "Restore failed at statement {$result->errors[0]->statementIndex}: "
        . $result->errors[0]->errorMessage
    );
}

printf("Restore complete: %d statements in %.1f ms\n",
    $result->statementsExecuted, $result->elapsedMs);
```

---

## Best Practices

- Always use a `GzipSink` for production backups — SQL dumps compress 5–10x.
- Prefer `DumpScope::tables()` over `DumpScope::database()` when you only need specific tables, to avoid exporting system or log tables.
- Set `includeTriggers: false` and `includeRoutines: false` unless you specifically need them — they add complexity to the round-trip.
- Use `wrapInTransaction: true` on import only for small, fully-DML scripts. Avoid it for scripts containing DDL (`CREATE TABLE`, `ALTER TABLE`) because DDL causes implicit commits on MySQL and SQL Server.
- Test your export/import pipeline against a staging environment before using it for production recovery.
- Monitor `ImportProgressEvent` in long-running imports to detect stalls early.
- Use `stopOnError: false` with a post-import check of `$result->errors` rather than aborting immediately — this gives you a complete picture of what failed.
