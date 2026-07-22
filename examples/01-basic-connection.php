<?php

declare(strict_types=1);

// Minimal connect → write → stream rows loop (in-memory SQLite).

require dirname(__DIR__) . '/vendor/autoload.php';

use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Driver\SqliteDriver;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\ConnectionParameters;

// Driver owns DSN/platform; connection is the only I/O surface.
$connection = (new SqliteDriver(
    new PdoConnectionFactory(new PdoExceptionTranslator),
    new SqlitePlatform,
))->connect(new ConnectionParameters(database: ':memory:'));

$connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
$connection->execute('INSERT INTO users (name) VALUES (?)', ['Ada']);

// query() streams rows; no full buffer unless you ask for it.
foreach ($connection->query('SELECT id, name FROM users') as $row) {
    printf("%d: %s\n", $row['id'], $row['name']);
}

$connection->close();
