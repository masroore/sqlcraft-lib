# Format & Sink Registry — Current API and Extension Patterns

> **Authoritative replacement:** `docs/other/plans/extensions-revised/04-implementation-handoff.md` and `03-verification.md`. This document is retained for history and is not an active implementation requirement.


> **Status:** SUPERSEDED — historical reference only
> **Phase:** 0 (the interfaces exist; this documents patterns and missing impls)
> **Namespace:** `SQLCraft\Export\`, `SQLCraft\Import\`

---

## 1. What Already Exists

The `FormatRegistry` is **fully implemented** in `src/Export/FormatRegistry.php`.

Current public API:

```php
final class FormatRegistry
{
    public function __construct(iterable $writers = [], iterable $readers = [])
    public function registerWriter(FormatWriterInterface $writer): void
    public function registerReader(FormatReaderInterface $reader): void
    public function getWriter(string $format): FormatWriterInterface
    public function getReader(string $format): FormatReaderInterface
    public function getSupportedWriteFormats(): array  // list<string>
    public function getSupportedReadFormats(): array   // list<string>
}
```

This fully covers Adminer's `dumpFormat` and `dumpOutput` plugin hooks. No changes to the registry itself are needed.

---

## 2. Missing Format Implementations

SQLCraft currently ships: `SqlFormatWriter`, `CsvFormatWriter`, `TsvFormatWriter`, `CsvSemicolonFormatWriter`, `CsvFormatReader`.

**Missing format writers** (Adminer plugin equivalents):

### 2.1 `JsonFormatWriter`

Adminer equivalent: `dump-json.php`

```php
// File: src/Export/JsonFormatWriter.php
final class JsonFormatWriter implements FormatWriterInterface
{
    public function getFormatName(): string { return 'json'; }

    public function writeHeader(SinkInterface $sink, ConnectionInterface $connection): void
    {
        $sink->write("[\n");
    }

    public function writeTableHeader(SinkInterface $sink, string $table, array $columns): void
    {
        // JSON arrays don't need table headers, track state internally
    }

    public function writeRows(SinkInterface $sink, string $table, iterable $rows, array $columns): void
    {
        foreach ($rows as $row) {
            $sink->write('  ' . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ",\n");
        }
    }

    public function writeTableFooter(SinkInterface $sink, string $table): void {}

    public function writeFooter(SinkInterface $sink): void
    {
        $sink->write("]\n");
    }
}
```

**Notes:**
- Must handle `null` values, binary blobs (base64 encode), and DateTime objects
- Should support options: pretty-print vs compact, null handling strategy

### 2.2 `XmlFormatWriter`

Adminer equivalent: `dump-xml.php`

```php
// File: src/Export/XmlFormatWriter.php
final class XmlFormatWriter implements FormatWriterInterface
{
    public function getFormatName(): string { return 'xml'; }

    public function writeHeader(SinkInterface $sink, ConnectionInterface $connection): void
    {
        $sink->write("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<dump>\n");
    }

    public function writeRows(SinkInterface $sink, string $table, iterable $rows, array $columns): void
    {
        foreach ($rows as $row) {
            $sink->write("  <row table=\"" . htmlspecialchars($table) . "\">\n");
            foreach ($row as $col => $val) {
                $escaped = $val === null ? '' : htmlspecialchars((string)$val);
                $attr = $val === null ? ' null="true"' : '';
                $sink->write("    <col name=\"" . htmlspecialchars($col) . "\"$attr>$escaped</col>\n");
            }
            $sink->write("  </row>\n");
        }
    }

    public function writeFooter(SinkInterface $sink): void
    {
        $sink->write("</dump>\n");
    }
}
```

### 2.3 `PhpFormatWriter`

Adminer equivalent: `dump-php.php`

```php
// File: src/Export/PhpFormatWriter.php
final class PhpFormatWriter implements FormatWriterInterface
{
    public function getFormatName(): string { return 'php'; }

    public function writeHeader(SinkInterface $sink, ConnectionInterface $connection): void
    {
        $sink->write("<?php\n// SQLCraft PHP dump\n\n");
    }

    public function writeRows(SinkInterface $sink, string $table, iterable $rows, array $columns): void
    {
        foreach ($rows as $row) {
            $sink->write('$db->insert(' . var_export($table, true) . ', ' . var_export($row, true) . ");\n");
        }
    }
}
```

---

## 3. Missing Sink Implementations

SQLCraft currently ships: `StreamSink`, `GzipSink`, `Bzip2Sink`.

**Missing sinks:**

### 3.1 `ZipArchiveSink`

Adminer equivalent: `dump-zip.php`

```php
// File: src/Export/ZipArchiveSink.php
final class ZipArchiveSink implements SinkInterface
{
    private \ZipArchive $zip;
    private string $entryName;
    private string $buffer = '';

    public function __construct(private readonly string $zipPath, string $entryName = 'dump.sql')
    {
        if (!extension_loaded('zip')) {
            throw new ExtensionMissingException('zip');
        }
        $this->zip = new \ZipArchive;
        $this->zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $this->entryName = $entryName;
    }

    public function write(string $bytes): void { $this->buffer .= $bytes; }
    public function flush(): void {}
    public function close(): void
    {
        $this->zip->addFromString($this->entryName, $this->buffer);
        $this->zip->close();
    }
}
```

### 3.2 `StringBufferSink`

For testing and in-memory use:

```php
// File: src/Export/StringBufferSink.php
final class StringBufferSink implements SinkInterface
{
    private string $buffer = '';

    public function write(string $bytes): void { $this->buffer .= $bytes; }
    public function flush(): void {}
    public function close(): void {}
    public function getContents(): string { return $this->buffer; }
    public function reset(): void { $this->buffer = ''; }
}
```

**Note:** `StringBufferSink` is invaluable for tests. Already common pattern — should ship with SQLCraft core.

---

## 4. Missing Import Source Implementations

SQLCraft currently ships: `FileImportSource`.

**Missing:**

### 4.1 `StringImportSource`

For testing and programmatic use:

```php
// File: src/Import/StringImportSource.php
final class StringImportSource implements ImportSourceInterface
{
    public function __construct(private readonly string $sql) {}

    public function getStream(): \Traversable { yield $this->sql; }
    public function getEstimatedBytes(): ?int { return strlen($this->sql); }
    public function getLabel(): string { return 'inline-string'; }
}
```

### 4.2 `UrlImportSource`

For fetching SQL from a remote URL (S3, HTTP, etc.):

```php
// File: src/Import/UrlImportSource.php
final class UrlImportSource implements ImportSourceInterface
{
    public function __construct(
        private readonly string $url,
        private readonly array $context = [],
    ) {}

    public function getStream(): \Traversable
    {
        $stream = fopen($this->url, 'r', false, stream_context_create($this->context));
        if ($stream === false) {
            throw new \RuntimeException("Unable to open: {$this->url}");
        }
        try {
            while (!feof($stream)) {
                yield fread($stream, 8192);
            }
        } finally {
            fclose($stream);
        }
    }
    // ...
}
```

---

## 5. `FormatWriterInterface` — Current Contract

```php
// src/Contracts/Export/FormatWriterInterface.php
interface FormatWriterInterface
{
    public function getFormatName(): string;
}
```

**Missing methods that plan14 requires** (need verification against current implementation):
- `writeHeader(SinkInterface $sink, ConnectionInterface $connection): void`
- `writeTableHeader(SinkInterface $sink, string $table, array $columns): void`
- `writeRows(SinkInterface $sink, string $table, iterable $rows, array $columns): void`
- `writeTableFooter(SinkInterface $sink, string $table): void`
- `writeFooter(SinkInterface $sink): void`

**Action required:** Read the actual `FormatWriterInterface` contract and all concrete implementations before planning any method additions to verify the current surface.

---

## 6. Testing Requirements

| Test | Type |
|---|---|
| `JsonFormatWriter` produces valid JSON | Unit |
| `XmlFormatWriter` produces valid XML with escaping | Unit |
| `PhpFormatWriter` produces syntactically valid PHP | Unit |
| `ZipArchiveSink` produces a valid ZIP file | Unit |
| `StringBufferSink` accumulates writes | Unit |
| `StringImportSource` yields full SQL | Unit |
| All writers registered via `registerWriter()` appear in `getSupportedWriteFormats()` | Integration |
| Extension-registered writer is used by `Exporter` | Integration |

---

## 7. File Summary

| File | New/Modified |
|---|---|
| `src/Export/JsonFormatWriter.php` | 🆕 New |
| `src/Export/XmlFormatWriter.php` | 🆕 New |
| `src/Export/PhpFormatWriter.php` | 🆕 New |
| `src/Export/ZipArchiveSink.php` | 🆕 New |
| `src/Export/StringBufferSink.php` | 🆕 New |
| `src/Import/StringImportSource.php` | 🆕 New |
| `src/Import/UrlImportSource.php` | 🆕 New |
| `tests/Export/JsonFormatWriterTest.php` | 🆕 New |
| `tests/Export/XmlFormatWriterTest.php` | 🆕 New |
| `tests/Export/PhpFormatWriterTest.php` | 🆕 New |
| `tests/Export/StringBufferSinkTest.php` | 🆕 New |
| `tests/Import/StringImportSourceTest.php` | 🆕 New |
