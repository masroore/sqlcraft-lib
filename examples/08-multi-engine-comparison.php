<?php

declare(strict_types=1);

// Same operation against multiple connections — swap Driver/Platform for real engines.

require dirname(__DIR__) . '/vendor/autoload.php';

use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Driver\SqliteDriver;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\ConnectionParameters;

// Business logic only sees ConnectionInterface — engine stays at the edge.
function countRows(ConnectionInterface $connection): int
{
    $connection->execute('CREATE TABLE orders (id INTEGER PRIMARY KEY)');
    $connection->execute('INSERT INTO orders DEFAULT VALUES');

    return count($connection->query('SELECT id FROM orders')->fetchAll());
}

$factory = new PdoConnectionFactory(new PdoExceptionTranslator);
foreach (['sqlite-memory-a', 'sqlite-memory-b', 'sqlite-memory-c'] as $label) {
    // In production each label would pick MySQLDriver / PostgreSQLDriver / etc.
    $connection = (new SqliteDriver($factory, new SqlitePlatform))
        ->connect(new ConnectionParameters(database: ':memory:'));
    printf("%s: %d row\n", $label, countRows($connection));
    $connection->close();
}
