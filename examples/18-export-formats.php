<?php

declare(strict_types=1);

/**
 * Export the same two tables as JSON, XML, XLSX, and HTML.
 *
 * SQL baseline: examples/12-structured-export.php
 * Artifacts:    examples/out/users-orders.{json,xml,xlsx,html}
 *
 * Run: php examples/18-export-formats.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Driver\SqliteDriver;
use SQLCraft\Execution\QueryExecutor;
use SQLCraft\Export\DumpOptions;
use SQLCraft\Export\DumpScope;
use SQLCraft\Export\Exporter;
use SQLCraft\Export\FormatRegistry;
use SQLCraft\Export\HtmlExportOptions;
use SQLCraft\Export\HtmlFormatWriter;
use SQLCraft\Export\JsonExportOptions;
use SQLCraft\Export\JsonFormatWriter;
use SQLCraft\Export\ResourceSink;
use SQLCraft\Export\XlsxExportOptions;
use SQLCraft\Export\XlsxFormatWriter;
use SQLCraft\Export\XmlExportOptions;
use SQLCraft\Export\XmlFormatWriter;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\Schema\SchemaManagerFactory;
use SQLCraft\ValueObjects\ConnectionParameters;

// ── Connection + sample data ────────────────────────────────────────────────

$connection = (new SqliteDriver(
    new PdoConnectionFactory(new PdoExceptionTranslator),
    new SqlitePlatform,
))->connect(new ConnectionParameters(database: ':memory:'));

$connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
$connection->execute('CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER, total INTEGER)');
$connection->execute('INSERT INTO users (name) VALUES (?), (?)', ['Ada', 'Grace']);
$connection->execute(
    'INSERT INTO orders (user_id, total) VALUES (?, ?), (?, ?), (?, ?)',
    [1, 120, 1, 45, 2, 90],
);

// ── One exporter, four format writers ───────────────────────────────────────

$exporter = new Exporter(
    SchemaManagerFactory::exportSourceForConnection($connection),
    new QueryExecutor,
    new FormatRegistry([
        new JsonFormatWriter,
        new XmlFormatWriter,
        new XlsxFormatWriter,
        new HtmlFormatWriter,
    ]),
);

$outDir = __DIR__ . '/out';
$stem = $outDir . '/users-orders';

foreach (['json', 'xml', 'xlsx', 'html'] as $format) {
    $path = $stem.'.' . $format;
    $bytes = exportToFile($exporter, $connection, $path, dumpOptions($format));
    printf("%s: %s (%d bytes)\n", $format, relativePath($path), $bytes);
}

$connection->close();

// ── Helpers ─────────────────────────────────────────────────────────────────

function dumpOptions(string $format): DumpOptions
{
    $scope = DumpScope::tables('main', ['users', 'orders']);

    return match ($format) {
        'json' => new DumpOptions(
            format: 'json',
            scope: $scope,
            jsonOptions: new JsonExportOptions(pretty: false),
        ),
        'xml' => new DumpOptions(
            format: 'xml',
            scope: $scope,
            xmlOptions: new XmlExportOptions(rootElement: 'dump', rowElement: 'record'),
        ),
        'xlsx' => new DumpOptions(
            format: 'xlsx',
            scope: $scope,
            xlsxOptions: new XlsxExportOptions(sheetPrefix: 'db_'),
        ),
        'html' => new DumpOptions(
            format: 'html',
            scope: $scope,
            htmlOptions: new HtmlExportOptions(title: 'Users & Orders Export'),
        ),
        default => throw new InvalidArgumentException(sprintf('Unsupported demo format: %s', $format)),
    };
}

function exportToFile(
    Exporter $exporter,
    ConnectionInterface $connection,
    string $path,
    DumpOptions $options,
): int {
    $directory = dirname($path);
    if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        throw new RuntimeException(sprintf('Unable to create %s', $directory));
    }

    $handle = fopen($path, 'wb');
    if ($handle === false) {
        throw new RuntimeException(sprintf('Unable to open %s for writing', $path));
    }

    $sink = new ResourceSink($handle);
    try {
        $exporter->export($connection, $sink, $options);
    } finally {
        $sink->close();
    }

    $size = filesize($path);

    return $size === false ? 0 : $size;
}

function relativePath(string $absolutePath): string
{
    $root = dirname(__DIR__) . DIRECTORY_SEPARATOR;
    if (str_starts_with($absolutePath, $root)) {
        return str_replace(DIRECTORY_SEPARATOR, '/', substr($absolutePath, strlen($root)));
    }

    return $absolutePath;
}
