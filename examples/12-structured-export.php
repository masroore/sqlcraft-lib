<?php

declare(strict_types=1);

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

$connectionFactory = new PdoConnectionFactory(new PdoExceptionTranslator);
$platform = new SqlitePlatform;
$driver = new SqliteDriver($connectionFactory, $platform);
$connection = $driver->connect(new ConnectionParameters(database: ':memory:'));

$connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
$connection->execute('INSERT INTO users (name) VALUES (?)', ['Ada']);
$connection->execute('INSERT INTO users (name) VALUES (?)', ['Grace']);

$source = SchemaManagerFactory::exportSourceForConnection($connection);
$executor = new QueryExecutor;
$writer = new SqlFormatWriter($connection);
$sink = new StringBufferSink;
$exporter = new Exporter($source, $executor, $writer);

$exporter->export(
    $connection,
    $sink,
    new DumpOptions(
        format: 'sql',
        scope: DumpScope::table('main', 'users'),
    ),
);

echo $sink->contents();
$connection->close();
