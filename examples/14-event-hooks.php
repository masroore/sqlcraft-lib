<?php

declare(strict_types=1);

// Attach a PSR-14 listener; QueryExecutor dispatches AfterQueryExecuted.

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

// Built-in dispatcher/provider — swap for your framework's PSR-14 stack.
$listenerProvider = new SimpleListenerProvider;
$listenerProvider->listen(
    AfterQueryExecuted::class,
    function (AfterQueryExecuted $event): void {
        printf("Query executed: %s (%.2fms)\n", $event->sql, $event->elapsedMs);
    },
);

$connection = (new SqliteDriver(
    new PdoConnectionFactory(new PdoExceptionTranslator),
    new SqlitePlatform,
))->connect(new ConnectionParameters(database: ':memory:'));

// Pass the dispatcher into the executor (connection alone won't fire these).
$executor = new QueryExecutor(events: new SimpleEventDispatcher($listenerProvider));

$executor->execute($connection, 'CREATE TABLE test (id INTEGER)');
$executor->execute($connection, 'INSERT INTO test VALUES (1)');
$executor->query($connection, 'SELECT * FROM test');

$connection->close();
