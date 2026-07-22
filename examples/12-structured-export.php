<?php

declare(strict_types=1);

// Official SQL export path: Exporter + SqlFormatWriter + in-memory sink.
// Multi-format tour: examples/18-export-formats.php

require dirname(__DIR__) . '/vendor/autoload.php';

use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Driver\SqliteDriver;
use SQLCraft\Execution\QueryExecutor;
use SQLCraft\Export\DumpOptions;
use SQLCraft\Export\DumpScope;
use SQLCraft\Export\Exporter;
use SQLCraft\Export\SqlFormatWriter;
use SQLCraft\Export\StringBufferSink;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\Schema\SchemaManagerFactory;
use SQLCraft\ValueObjects\ConnectionParameters;

$connection = (new SqliteDriver(
    new PdoConnectionFactory(new PdoExceptionTranslator),
    new SqlitePlatform,
))->connect(new ConnectionParameters(database: ':memory:'));

$connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
$connection->execute('INSERT INTO users (name) VALUES (?)', ['Ada']);
$connection->execute('INSERT INTO users (name) VALUES (?)', ['Grace']);

// Source supplies table metadata; writer owns SQL rendering; sink receives bytes.
$exporter = new Exporter(
    SchemaManagerFactory::exportSourceForConnection($connection),
    new QueryExecutor,
    new SqlFormatWriter($connection),
);

$sink = new StringBufferSink;
$exporter->export(
    $connection,
    $sink,
    new DumpOptions(
        format: 'sql',
        scope: DumpScope::table('main', 'users'), // SQLite default schema is "main"
    ),
);

echo $sink->contents();
$connection->close();
