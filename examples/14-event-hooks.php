<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Driver\SqliteDriver;
use SQLCraft\Events\AfterQueryExecuted;
use SQLCraft\Events\SimpleEventDispatcher;
use SQLCraft\Events\SimpleListenerProvider;
use SQLCraft\Execution\QueryExecutor;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\ConnectionParameters;

$listenerProvider = new SimpleListenerProvider;
$listenerProvider->listen(
    AfterQueryExecuted::class,
    function (AfterQueryExecuted $event): void {
        printf("Query executed: %s (%.2fms)\n", $event->sql, $event->elapsedMs);
    },
);

$dispatcher = new SimpleEventDispatcher($listenerProvider);
$connectionFactory = new PdoConnectionFactory(new PdoExceptionTranslator);
$platform = new SqlitePlatform;
$driver = new SqliteDriver($connectionFactory, $platform);
$connection = $driver->connect(new ConnectionParameters(database: ':memory:'));

$executor = new QueryExecutor(events: $dispatcher);

$executor->execute($connection, 'CREATE TABLE test (id INTEGER)');
$executor->execute($connection, 'INSERT INTO test VALUES (1)');
$executor->query($connection, 'SELECT * FROM test');

$connection->close();
