# Export Format Expansion: JSON, XML, XLSX, HTML

**Status**: Planned — do not implement anything outside this document's sequence.  
**Formats to add**: `json`, `xml`, `xlsx`, `html`  
**Parquet**: deferred to a future plan.

---

## Context & Constraints

- This is a **zero-runtime-dependency library** (`php: ^8.4`, `ext-pdo` only).
- `openspout/openspout` will be added to `require` (XLSX needs it; no native PHP XLSX support).
- `twig/twig` will be added to `suggest` only — the HTML writer detects it at runtime via `class_exists`.
- All new format writers implement the existing `SQLCraft\Contracts\Export\FormatWriterInterface`.
- `DumpOptions` is `final readonly` — extend it by adding nullable DTO properties at the end of its constructor.
- **DDL**: all four new writers implement `writeTableDdl()` as a no-op.
- **Null handling**: format-native (JSON `null`, XML empty element, XLSX empty cell, HTML blank/em-dash). The `$nullRepresentation` field on `DumpOptions` is ignored by these writers.
- **Binary columns**: base64-encode (same as existing `AbstractDelimitedFormatWriter`).
- **Multi-table structure**: array-of-objects, each entry `{"table":"name","rows":[...]}` (order preserved by FK topological sort).

---

## Files to Create

```
src/Export/JsonExportOptions.php
src/Export/XmlExportOptions.php
src/Export/XlsxExportOptions.php
src/Export/HtmlExportOptions.php
src/Export/JsonFormatWriter.php
src/Export/XmlFormatWriter.php
src/Export/XlsxFormatWriter.php
src/Export/HtmlFormatWriter.php
src/Export/Html/TemplateEngineInterface.php
src/Export/Html/BladeTemplateEngine.php
src/Export/Html/TwigTemplateEngine.php
src/Export/Html/TemplateEngineFactory.php
src/Export/Html/default-template.html
tests/Unit/Export/JsonFormatWriterTest.php
tests/Unit/Export/XmlFormatWriterTest.php
tests/Unit/Export/XlsxFormatWriterTest.php
tests/Unit/Export/HtmlFormatWriterTest.php
tests/Unit/Export/Html/BladeTemplateEngineTest.php
```

## Files to Modify

```
src/Export/DumpOptions.php           — add four new nullable DTO constructor params
composer.json                        — add openspout to require, twig to suggest
```

---

## Phase 1 — Option DTOs

Create four small `final readonly` classes. No logic, just typed constructor properties with defaults.

### 1.1 `src/Export/JsonExportOptions.php`

```php
<?php

declare(strict_types=1);

namespace SQLCraft\Export;

final readonly class JsonExportOptions
{
    public function __construct(
        public bool $pretty = true,
    ) {}
}
```

### 1.2 `src/Export/XmlExportOptions.php`

```php
<?php

declare(strict_types=1);

namespace SQLCraft\Export;

final readonly class XmlExportOptions
{
    public function __construct(
        public string $rootElement = 'export',
        public string $rowElement = 'row',
    ) {}
}
```

`$rootElement` is the document root tag name. `$rowElement` is each data row's tag name.
Column names become child element names (sanitised — see `XmlFormatWriter`).

### 1.3 `src/Export/XlsxExportOptions.php`

```php
<?php

declare(strict_types=1);

namespace SQLCraft\Export;

final readonly class XlsxExportOptions
{
    public function __construct(
        public ?string $sheetPrefix = null,
        public bool $freezeHeaderRow = true,
    ) {}
}
```

`$sheetPrefix`: if `'db_'`, a table named `users` becomes sheet `db_users`.

### 1.4 `src/Export/HtmlExportOptions.php`

```php
<?php

declare(strict_types=1);

namespace SQLCraft\Export;

final readonly class HtmlExportOptions
{
    public function __construct(
        public ?string $templatePath = null,
        public ?string $templateString = null,
        public string $title = 'Database Export',
        public bool $useTwig = false,
    ) {}
}
```

Precedence: `$templatePath` > `$templateString` > bundled default template.
`$useTwig = true` requires `twig/twig` to be installed; throws `\RuntimeException` if missing.

---

## Phase 2 — Update `DumpOptions`

Open `src/Export/DumpOptions.php`. Add four new nullable parameters **at the end** of the constructor. Do not change any existing parameters or their defaults.

```php
// Existing constructor params stay unchanged above.
// Add these at the end:
public ?JsonExportOptions $jsonOptions = null,
public ?XmlExportOptions $xmlOptions = null,
public ?XlsxExportOptions $xlsxOptions = null,
public ?HtmlExportOptions $htmlOptions = null,
```

The complete constructor signature will be:

```php
public function __construct(
    public string $format,
    public DumpScope $scope,
    public DatabaseSectionStyle $databaseStyle = DatabaseSectionStyle::None,
    public TableSectionStyle $tableStyle = TableSectionStyle::DropCreate,
    public DataStyle $dataStyle = DataStyle::Insert,
    public bool $includeAutoIncrement = true,
    public bool $includeTriggers = false,
    public bool $includeRoutines = false,
    public bool $includeEvents = false,
    public bool $includeUserTypes = false,
    public int $batchSize = 100,
    public ?string $csvSeparator = null,
    public string $nullRepresentation = '\\N',
    public ?JsonExportOptions $jsonOptions = null,
    public ?XmlExportOptions $xmlOptions = null,
    public ?XlsxExportOptions $xlsxOptions = null,
    public ?HtmlExportOptions $htmlOptions = null,
) {
    // Existing validation stays unchanged.
}
```

Also find the `exportAllDatabases` method in `src/Export/Exporter.php`. It constructs a `new DumpOptions(...)` manually by repeating all named arguments. Add the four new ones there too:

```php
jsonOptions: $options->jsonOptions,
xmlOptions: $options->xmlOptions,
xlsxOptions: $options->xlsxOptions,
htmlOptions: $options->htmlOptions,
```

---

## Phase 3 — HTML Template Engine

Three files + one HTML resource. Create them in `src/Export/Html/`.

### 3.1 `src/Export/Html/TemplateEngineInterface.php`

```php
<?php

declare(strict_types=1);

namespace SQLCraft\Export\Html;

interface TemplateEngineInterface
{
    /**
     * Render a template string with the given data.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data): string;
}
```

### 3.2 `src/Export/Html/BladeTemplateEngine.php`

Implements `TemplateEngineInterface`. Compiles a small subset of Blade-like syntax to PHP, then evaluates it in an isolated scope.

**Supported directives (compile step):**

| Input | Compiled to |
|---|---|
| `{{ $var }}` | `<?= htmlspecialchars((string)($var), ENT_QUOTES, 'UTF-8') ?>` |
| `{!! $var !!}` | `<?= $var ?>` |
| `@foreach($x as $y)` | `<?php foreach($x as $y): ?>` |
| `@endforeach` | `<?php endforeach; ?>` |
| `@if($cond)` | `<?php if($cond): ?>` |
| `@elseif($cond)` | `<?php elseif($cond): ?>` |
| `@else` | `<?php else: ?>` |
| `@endif` | `<?php endif; ?>` |

**`render()` implementation:**

1. Call `compile($template)` to get the PHP string.
2. Write to a temp file: `$tmp = tempnam(sys_get_temp_dir(), 'sqlcraft_html_')`.
3. `file_put_contents($tmp, $compiled)`.
4. Use a static anonymous function with `extract($data, EXTR_SKIP)` + `ob_start()` + `include $tmp` to capture output.
5. `ob_get_clean()` to get the result.
6. `unlink($tmp)` to clean up.
7. Return the output string.

**Error handling**: wrap in try/finally to ensure `unlink()` always runs even if the template throws.

```php
<?php

declare(strict_types=1);

namespace SQLCraft\Export\Html;

final class BladeTemplateEngine implements TemplateEngineInterface
{
    #[\Override]
    public function render(string $template, array $data): string
    {
        $compiled = $this->compile($template);
        $tmp = tempnam(sys_get_temp_dir(), 'sqlcraft_html_');
        if ($tmp === false) {
            throw new \RuntimeException('Failed to create temporary file for HTML template rendering.');
        }

        file_put_contents($tmp, $compiled);

        try {
            return (static function (string $__path, array $__data): string {
                extract($__data, EXTR_SKIP);
                ob_start();
                try {
                    include $__path;
                } catch (\Throwable $e) {
                    ob_end_clean();
                    throw $e;
                }
                return (string) ob_get_clean();
            })($tmp, $data);
        } finally {
            @unlink($tmp);
        }
    }

    private function compile(string $template): string
    {
        // Escaped output: {{ $var }}
        $template = preg_replace(
            '/\{\{\s*(.+?)\s*\}\}/',
            '<?= htmlspecialchars((string)($1), ENT_QUOTES, \'UTF-8\') ?>',
            $template,
        );

        // Raw output: {!! $var !!}
        $template = preg_replace('/\{!!\s*(.+?)\s*!!\}/', '<?= $1 ?>', $template);

        // Control structures
        $template = preg_replace('/@foreach\((.+?)\)/', '<?php foreach($1): ?>', $template);
        $template = preg_replace('/@endforeach/', '<?php endforeach; ?>', $template);
        $template = preg_replace('/@if\((.+?)\)/', '<?php if($1): ?>', $template);
        $template = preg_replace('/@elseif\((.+?)\)/', '<?php elseif($1): ?>', $template);
        $template = preg_replace('/@else/', '<?php else: ?>', $template);
        $template = preg_replace('/@endif/', '<?php endif; ?>', $template);

        return (string) $template;
    }
}
```

### 3.3 `src/Export/Html/TwigTemplateEngine.php`

Only instantiate this class if `twig/twig` is installed. The `TemplateEngineFactory` handles the check.

```php
<?php

declare(strict_types=1);

namespace SQLCraft\Export\Html;

use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class TwigTemplateEngine implements TemplateEngineInterface
{
    #[\Override]
    public function render(string $template, array $data): string
    {
        $loader = new ArrayLoader(['template' => $template]);
        $twig = new Environment($loader, ['autoescape' => 'html']);

        return $twig->render('template', $data);
    }
}
```

Note: each `render()` call creates a fresh `Environment`. For export use-cases (one render per export) this is fine. If performance ever matters, the factory can cache the instance.

### 3.4 `src/Export/Html/TemplateEngineFactory.php`

```php
<?php

declare(strict_types=1);

namespace SQLCraft\Export\Html;

use SQLCraft\Export\HtmlExportOptions;

final class TemplateEngineFactory
{
    public static function create(HtmlExportOptions $options): TemplateEngineInterface
    {
        if ($options->useTwig) {
            if (! class_exists(\Twig\Environment::class)) {
                throw new \RuntimeException(
                    'twig/twig is not installed. Run: composer require twig/twig',
                );
            }

            return new TwigTemplateEngine();
        }

        return new BladeTemplateEngine();
    }

    /**
     * Resolve the template string from options, falling back to the bundled default.
     */
    public static function resolveTemplate(HtmlExportOptions $options): string
    {
        if ($options->templatePath !== null) {
            if (! is_readable($options->templatePath)) {
                throw new \RuntimeException(
                    sprintf('HTML template file not readable: %s', $options->templatePath),
                );
            }

            return (string) file_get_contents($options->templatePath);
        }

        if ($options->templateString !== null) {
            return $options->templateString;
        }

        return (string) file_get_contents(__DIR__ . '/default-template.html');
    }
}
```

### 3.5 `src/Export/Html/default-template.html`

A complete, self-contained HTML5 document. Uses only inline CSS (no external assets). Template variables available: `$title`, `$exportedAt`, `$databaseName`, `$tables`.

Each entry in `$tables` is an associative array:
```php
[
    'name'    => string,           // table name
    'columns' => ColumnMeta[],     // list<ColumnMeta>
    'rows'    => array[],          // list<array<string,mixed>>
]
```

`ColumnMeta` has public properties: `name`, `dataType`, `nullable`, `default`.

The default template must:
- Render a full `<!DOCTYPE html>` document.
- Show `$title` in `<title>` and an `<h1>`.
- Show `$databaseName` and `$exportedAt->format('Y-m-d H:i:s')` in a metadata bar.
- Loop `@foreach($tables as $table)` and render each as a `<section>` with an `<h2>{{ $table['name'] }}` heading and a `<table>`.
- Table `<thead>` uses `$table['columns']` — one `<th>` per column, with `title="{{ $column->dataType->name }}"` for type tooltip.
- Table `<tbody>` loops `$table['rows']` — one `<td>` per column value, empty cell for null.
- Inline CSS only — no external stylesheets, no JavaScript.

```html
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $title }}</title>
<style>
  body { font-family: system-ui, sans-serif; margin: 2rem; color: #1a1a1a; }
  h1 { font-size: 1.5rem; margin-bottom: .25rem; }
  .meta { font-size: .85rem; color: #666; margin-bottom: 2rem; }
  section { margin-bottom: 3rem; }
  h2 { font-size: 1.1rem; border-bottom: 2px solid #e5e7eb; padding-bottom: .4rem; }
  table { border-collapse: collapse; width: 100%; font-size: .875rem; }
  th, td { border: 1px solid #e5e7eb; padding: .4rem .75rem; text-align: left; }
  th { background: #f9fafb; font-weight: 600; }
  tr:nth-child(even) { background: #f9fafb; }
  .null { color: #aaa; font-style: italic; }
</style>
</head>
<body>
<h1>{{ $title }}</h1>
<p class="meta">Database: <strong>{{ $databaseName }}</strong> &mdash; Exported: {{ $exportedAt->format('Y-m-d H:i:s') }}</p>
@foreach($tables as $table)
<section>
  <h2>{{ $table['name'] }}</h2>
  <table>
    <thead>
      <tr>
        @foreach($table['columns'] as $column)
        <th title="{{ $column->dataType->name }}">{{ $column->name }}</th>
        @endforeach
      </tr>
    </thead>
    <tbody>
      @foreach($table['rows'] as $row)
      <tr>
        @foreach($table['columns'] as $column)
        @if($row[$column->name] === null)
        <td class="null">&mdash;</td>
        @else
        <td>{{ (string)$row[$column->name] }}</td>
        @endif
        @endforeach
      </tr>
      @endforeach
    </tbody>
  </table>
</section>
@endforeach
</body>
</html>
```

---

## Phase 4 — `JsonFormatWriter`

**File**: `src/Export/JsonFormatWriter.php`  
**Namespace**: `SQLCraft\Export`  
**Implements**: `FormatWriterInterface`

### Behaviour

- **Streaming** — outputs incrementally; no internal buffering.
- `writeHeader()` writes `[` (opening array).
- `writeTableHeader()` writes `\n  ` + `{"table":"<name>","rows":[` (opening object + rows array).  
  Track whether this is the first table to decide comma placement before `{`.
- `writeRows()` writes each row as a JSON object. Track whether the first row of the current table has been written for comma placement.
- `writeTableFooter()` writes `\n  ]}` (close rows array + object).
- `writeFooter()` writes `\n]` (close document array) + newline.
- `writeTableDdl()` is a **no-op**.

### JSON flags

```php
private function jsonFlags(DumpOptions $options): int
{
    $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE;
    $opts = $options->jsonOptions ?? new JsonExportOptions();
    if ($opts->pretty) {
        $flags |= JSON_PRETTY_PRINT;
    }
    return $flags;
}
```

### Null & binary handling

```php
private function prepareValue(mixed $value, ColumnMeta $column): mixed
{
    if ($value === null) {
        return null;                    // JSON native null
    }
    if ($this->isBinary($column)) {
        return base64_encode((string) $value);
    }
    return match (true) {
        is_bool($value)  => $value,
        is_int($value)   => $value,
        is_float($value) => $value,
        default          => (string) $value,
    };
}
```

Use the same `isBinary()` helper as `AbstractDelimitedFormatWriter` (copy it or extract to a trait).

### State tracking

Keep two `bool` properties:
```php
private bool $firstTable = true;
private bool $firstRow   = true;
```

Reset `$firstRow = true` in `writeTableHeader()`. Set `$firstTable = false` after writing the first table entry.

### Complete class skeleton

```php
final class JsonFormatWriter implements FormatWriterInterface
{
    private bool $firstTable = true;
    private bool $firstRow   = true;

    public function getFormatName(): string { return 'json'; }

    public function writeHeader(SinkInterface $sink, DumpOptions $options): void
    {
        $sink->write('[');
    }

    public function writeTableHeader(SinkInterface $sink, TableStatus $table, DumpOptions $options): void
    {
        $prefix = $this->firstTable ? "\n  " : ",\n  ";
        $this->firstTable = false;
        $this->firstRow   = true;
        $sink->write($prefix . '{"table":' . json_encode($table->name, JSON_THROW_ON_ERROR) . ',"rows":[');
    }

    public function writeTableDdl(SinkInterface $sink, TableStatus $table, array $ddlStatements): void {}

    public function writeRows(SinkInterface $sink, TableStatus $table, array $rows, array $columns, DumpOptions $options): void
    {
        $flags = $this->jsonFlags($options);
        foreach ($rows as $row) {
            $record = [];
            foreach ($columns as $column) {
                $record[$column->name] = $this->prepareValue($row[$column->name] ?? null, $column);
            }
            $prefix = $this->firstRow ? "\n    " : ",\n    ";
            $this->firstRow = false;
            $sink->write($prefix . json_encode($record, $flags));
        }
    }

    public function writeTableFooter(SinkInterface $sink, TableStatus $table): void
    {
        $sink->write("\n  ]}");
    }

    public function writeFooter(SinkInterface $sink, DumpOptions $options): void
    {
        $sink->write("\n]\n");
    }
}
```

---

## Phase 5 — `XmlFormatWriter`

**File**: `src/Export/XmlFormatWriter.php`  
**Namespace**: `SQLCraft\Export`  
**Implements**: `FormatWriterInterface`

### Behaviour

- Uses PHP core `XMLWriter` (streaming, no external deps).
- `writeHeader()` creates the `XMLWriter`, starts the document + root element.
- `writeTableHeader()` opens `<table name="...">`.
- `writeRows()` writes each row as `<{rowElement}><colName>value</colName></{rowElement}>`.
- `writeTableFooter()` closes `</table>`.
- `writeFooter()` closes the root element, flushes writer to sink.
- `writeTableDdl()` is a **no-op**.

### Column name sanitisation

XML element names must match `[a-zA-Z_][\w.-]*`. Sanitise:
```php
private function sanitiseElementName(string $name): string
{
    $name = preg_replace('/[^a-zA-Z0-9_.\-]/', '_', $name);
    if (preg_match('/^[0-9\-.]/', $name)) {
        $name = '_' . $name;
    }
    return $name !== '' ? $name : '_col';
}
```

### State

Keep a single `?XMLWriter $writer` property. Initialise in `writeHeader()`, set to `null` after `writeFooter()`.

### Null handling

Null value → write an empty self-closing element: `$writer->writeElement($colName)` (no text node).

### Binary handling

Base64-encode, write as text content with an `encoding="base64"` attribute.

### Class skeleton

```php
final class XmlFormatWriter implements FormatWriterInterface
{
    private ?\XMLWriter $writer = null;

    public function getFormatName(): string { return 'xml'; }

    public function writeHeader(SinkInterface $sink, DumpOptions $options): void
    {
        $opts = $options->xmlOptions ?? new XmlExportOptions();
        $this->writer = new \XMLWriter();
        $this->writer->openMemory();
        $this->writer->setIndent(true);
        $this->writer->setIndentString('  ');
        $this->writer->startDocument('1.0', 'UTF-8');
        $this->writer->startElement($opts->rootElement);
    }

    public function writeTableHeader(SinkInterface $sink, TableStatus $table, DumpOptions $options): void
    {
        $this->writer()->startElement('table');
        $this->writer()->writeAttribute('name', $table->name);
    }

    public function writeTableDdl(SinkInterface $sink, TableStatus $table, array $ddlStatements): void {}

    public function writeRows(SinkInterface $sink, TableStatus $table, array $rows, array $columns, DumpOptions $options): void
    {
        $opts = $options->xmlOptions ?? new XmlExportOptions();
        $w    = $this->writer();
        foreach ($rows as $row) {
            $w->startElement($opts->rowElement);
            foreach ($columns as $column) {
                $value = $row[$column->name] ?? null;
                $elem  = $this->sanitiseElementName($column->name);
                if ($value === null) {
                    $w->writeElement($elem);
                } elseif ($this->isBinary($column)) {
                    $w->startElement($elem);
                    $w->writeAttribute('encoding', 'base64');
                    $w->text(base64_encode((string) $value));
                    $w->endElement();
                } else {
                    $w->writeElement($elem, (string) $value);
                }
            }
            $w->endElement(); // row
            // Flush accumulated output to sink in batches to limit memory use.
            $sink->write($w->flush());
        }
    }

    public function writeTableFooter(SinkInterface $sink, TableStatus $table): void
    {
        $this->writer()->endElement(); // table
        $sink->write($this->writer()->flush());
    }

    public function writeFooter(SinkInterface $sink, DumpOptions $options): void
    {
        $w = $this->writer();
        $w->endElement(); // root
        $w->endDocument();
        $sink->write($w->flush());
        $this->writer = null;
    }

    private function writer(): \XMLWriter
    {
        return $this->writer ?? throw new \LogicException('XmlFormatWriter: writeHeader() not called.');
    }
}
```

---

## Phase 6 — `XlsxFormatWriter`

**File**: `src/Export/XlsxFormatWriter.php`  
**Namespace**: `SQLCraft\Export`  
**Implements**: `FormatWriterInterface`  
**Requires**: `openspout/openspout` (must be in `composer.json` `require` before using this class)

### Dependency

```
use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;
```

Check the installed OpenSpout version (`^4.0`) API. In v4, the writer is instantiated directly:
```php
$options = new Options();
$writer = new Writer($options);
$writer->openToFile($path);
```

### Temp-file strategy

OpenSpout writes to a file path, not to an arbitrary stream. Bridge to `SinkInterface` via:
1. `writeHeader()`: `$this->tmpPath = tempnam(sys_get_temp_dir(), 'sqlcraft_xlsx_')` then `$writer->openToFile($this->tmpPath)`.
2. All `writeRows()`/`writeTableHeader()` calls write to the OpenSpout writer normally.
3. `writeFooter()`: `$writer->close()`, then `$sink->write(file_get_contents($this->tmpPath))`, then `unlink($this->tmpPath)`.

Use try/finally in `writeFooter()` to ensure cleanup even on error.

### Sheet naming

```php
private function sheetName(TableStatus $table, DumpOptions $options): string
{
    $prefix = ($options->xlsxOptions ?? new XlsxExportOptions())->sheetPrefix ?? '';
    $name   = $prefix . $table->name;
    // Excel sheet names max 31 chars, no: \ / ? * [ ]
    $name = preg_replace('/[\\\\\/\?\*\[\]]/', '_', $name);
    return mb_substr($name, 0, 31);
}
```

### Header row

Write the column-name row as the first row of each sheet. If `$freezeHeaderRow = true`, use the OpenSpout freeze API:
```php
$sheet->setSheetView(
    (new SheetView())->setFreezeRow(2) // freeze rows above row 2
);
```
(Check OpenSpout v4 API for the exact class/method.)

### Cell value mapping

```php
private function cellValue(mixed $value, ColumnMeta $column): Cell
{
    if ($value === null) {
        return Cell::fromValue('');
    }
    if ($this->isBinary($column)) {
        return Cell::fromValue(base64_encode((string) $value));
    }
    return Cell::fromValue($value); // OpenSpout accepts int, float, bool, string, DateTime
}
```

### `writeTableHeader()` — new sheet per table

```php
public function writeTableHeader(SinkInterface $sink, TableStatus $table, DumpOptions $options): void
{
    // Add a new sheet (first table uses the sheet created on open; subsequent tables add new ones)
    if ($this->sheetCount > 0) {
        $this->writer()->addNewSheetAndMakeItCurrent();
    }
    $this->sheetCount++;
    $this->writer()->getCurrentSheet()->setName($this->sheetName($table, $options));
}
```

Track `private int $sheetCount = 0`.

### Class skeleton state

```php
private ?Writer     $writer    = null;
private ?string     $tmpPath   = null;
private int         $sheetCount = 0;
private bool        $headerRowWritten = false;
```

Reset `$headerRowWritten = false` in `writeTableHeader()`. Write the column-name row on the first `writeRows()` call if not yet written.

---

## Phase 7 — `HtmlFormatWriter`

**File**: `src/Export/HtmlFormatWriter.php`  
**Namespace**: `SQLCraft\Export`  
**Implements**: `FormatWriterInterface`

### Buffered strategy

HTML templates need all data before rendering. Buffer everything internally:

```php
/** @var list<array{name:string, columns:list<ColumnMeta>, rows:list<array<string,mixed>>}> */
private array $tables = [];

/** @var list<array<string,mixed>> */
private array $currentRows = [];

/** @var list<ColumnMeta> */
private array $currentColumns = [];

private string $currentTableName = '';
```

### Method behaviour

- `writeHeader()` — no-op (just reset state).
- `writeTableHeader()` — store table name; reset `$currentRows`, `$currentColumns`.
- `writeTableDdl()` — no-op.
- `writeRows()` — append rows to `$currentRows`; capture columns from first call.
- `writeTableFooter()` — push `['name' => $currentTableName, 'columns' => $currentColumns, 'rows' => $currentRows]` to `$this->tables`.
- `writeFooter()` — render the template with all buffered data, write result to sink, reset state.

### Data passed to template

```php
$data = [
    'title'        => $htmlOptions->title,
    'databaseName' => $options->scope->database ?? '',
    'exportedAt'   => new \DateTimeImmutable(),
    'tables'       => $this->tables,
];
```

### `writeFooter()` implementation

```php
public function writeFooter(SinkInterface $sink, DumpOptions $options): void
{
    $htmlOptions = $options->htmlOptions ?? new HtmlExportOptions();
    $engine      = TemplateEngineFactory::create($htmlOptions);
    $template    = TemplateEngineFactory::resolveTemplate($htmlOptions);
    $sink->write($engine->render($template, $this->buildData($options)));
    $this->tables = [];
}
```

### Memory note

For very large databases, buffering all rows in memory is the tradeoff of full-document HTML rendering. Document this in a docblock on the class.

---

## Phase 8 — `composer.json` Changes

### Add to `require`

```json
"openspout/openspout": "^4.0"
```

### Add to `suggest`

```json
"twig/twig": "^3.0 || ^4.0"
```

The full `suggest` block after change:

```json
"suggest": {
    "ext-pdo_mysql": "...",
    "ext-pdo_pgsql": "...",
    "ext-pdo_sqlite": "...",
    "ext-pdo_sqlsrv": "...",
    "ext-pdo_dblib": "...",
    "ext-pdo_oci": "...",
    "psr/simple-cache-implementation": "...",
    "psr/event-dispatcher-implementation": "...",
    "psr/log-implementation": "...",
    "twig/twig": "Enables Twig templating for HtmlFormatWriter (set HtmlExportOptions::$useTwig = true)."
}
```

---

## Phase 9 — Tests

All tests go in `tests/Unit/Export/`.

### `JsonFormatWriterTest.php`

- `testEmptyExport()` — no tables → outputs `[\n]\n`.
- `testSingleTableNoRows()` — table with zero rows → `[{"table":"users","rows":[]}]`.
- `testSingleTableWithRows()` — assert JSON structure matches Option B spec.
- `testMultipleTablesOrderPreserved()` — two tables; assert order.
- `testNullBecomesJsonNull()` — column with null value → `null` literal in JSON, not the string `\N`.
- `testBinaryColumnIsBase64Encoded()` — binary column → base64 string.
- `testCompactJson()` — `JsonExportOptions(pretty: false)` → no whitespace.
- `testPrettyJsonDefault()` — default options → output contains newlines.

### `XmlFormatWriterTest.php`

- `testDocumentStructure()` — valid XML, root element `<export>`, `<table name="...">`, `<row>`.
- `testCustomRootAndRowElements()` — `XmlExportOptions(rootElement: 'dump', rowElement: 'record')`.
- `testNullBecomesEmptyElement()` — null → `<col/>` self-closing.
- `testBinaryColumnBase64Attribute()` — binary → `<col encoding="base64">...</col>`.
- `testColumnNameSanitisation()` — column `1bad-col` → element `_1bad-col`.
- `testValidXmlOutput()` — run output through `simplexml_load_string()`, assert no parse error.

### `XlsxFormatWriterTest.php`

- `testSingleTableOneSheet()` — export one table; open resulting XLSX with OpenSpout reader; assert sheet name = table name.
- `testMultipleTablesMultipleSheets()` — two tables → two sheets.
- `testSheetPrefix()` — `XlsxExportOptions(sheetPrefix: 'db_')` → sheet named `db_users`.
- `testHeaderRowPresent()` — first row of sheet matches column names.
- `testNullBecomesEmptyCell()` — null value → empty string cell.
- `testBinaryBase64()` — binary value → base64 string in cell.
- `testTempFileCleanedUp()` — after export, no `sqlcraft_xlsx_*` temp files remain.

### `HtmlFormatWriterTest.php`

- `testDefaultTemplateRendersValidHtml()` — run output through `DOMDocument::loadHTML()`, assert no errors.
- `testTitleAppearsInOutput()` — `HtmlExportOptions(title: 'My Export')` → output contains `My Export`.
- `testTablesAndRowsPresent()` — two tables → both `<h2>` headings present, row data in `<td>`.
- `testNullRendersAsDash()` — null value → em-dash `—` in output.
- `testCustomTemplatePath()` — write a temp template file, pass path via `HtmlExportOptions`.
- `testCustomTemplateString()` — pass inline template string.
- `testUseTwigThrowsWhenNotInstalled()` — mock `class_exists` or use a test without Twig installed; assert `RuntimeException`.

### `BladeTemplateEngineTest.php`

- `testEscapedOutput()` — `{{ $var }}` with `<script>` → HTML-escaped.
- `testRawOutput()` — `{!! $var !!}` → unescaped.
- `testForeach()` — loop renders correct number of items.
- `testIfElseEndif()` — branching works.
- `testNestedForeachIf()` — real-world template subset.
- `testTempFileCleanedUpOnSuccess()` — no temp files remain.
- `testTempFileCleanedUpOnError()` — throwing template still cleans up temp file.

---

## Implementation Sequence

Follow this order strictly. Each phase depends on the previous.

1. **Phase 1** — Create `JsonExportOptions`, `XmlExportOptions`, `XlsxExportOptions`, `HtmlExportOptions`.
2. **Phase 2** — Update `DumpOptions` + `Exporter::exportAllDatabases()`.
3. **Phase 3** — Create `TemplateEngineInterface`, `BladeTemplateEngine`, `TwigTemplateEngine`, `TemplateEngineFactory`, `default-template.html`.
4. **Phase 8** — Update `composer.json` (add openspout), then run `composer update`.
5. **Phase 4** — Implement `JsonFormatWriter`.
6. **Phase 5** — Implement `XmlFormatWriter`.
7. **Phase 6** — Implement `XlsxFormatWriter`.
8. **Phase 7** — Implement `HtmlFormatWriter`.
9. **Phase 9** — Write all tests.
10. Run `composer test` and `composer stan` — fix any type errors before committing.

---

## Checklist

- [ ] `src/Export/JsonExportOptions.php` created
- [ ] `src/Export/XmlExportOptions.php` created
- [ ] `src/Export/XlsxExportOptions.php` created
- [ ] `src/Export/HtmlExportOptions.php` created
- [ ] `src/Export/DumpOptions.php` updated (4 new params)
- [ ] `src/Export/Exporter.php` updated (`exportAllDatabases` propagates new params)
- [ ] `src/Export/Html/TemplateEngineInterface.php` created
- [ ] `src/Export/Html/BladeTemplateEngine.php` created
- [ ] `src/Export/Html/TwigTemplateEngine.php` created
- [ ] `src/Export/Html/TemplateEngineFactory.php` created
- [ ] `src/Export/Html/default-template.html` created
- [ ] `src/Export/JsonFormatWriter.php` created
- [ ] `src/Export/XmlFormatWriter.php` created
- [ ] `src/Export/XlsxFormatWriter.php` created
- [ ] `src/Export/HtmlFormatWriter.php` created
- [ ] `composer.json` updated (openspout in require, twig in suggest)
- [ ] `composer update` run successfully
- [ ] All unit tests written and passing
- [ ] `composer stan` passes with no errors
- [ ] `composer cs:fix` run
