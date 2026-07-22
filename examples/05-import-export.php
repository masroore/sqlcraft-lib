<?php

declare(strict_types=1);

// Hand-rolled dump/restore with quoteValue — contrast with Exporter in 12/18.

require dirname(__DIR__) . '/vendor/autoload.php';

use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Driver\SqliteDriver;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\ConnectionParameters;

$connection = (new SqliteDriver(
    new PdoConnectionFactory(new PdoExceptionTranslator),
    new SqlitePlatform,
))->connect(new ConnectionParameters(database: ':memory:'));

$connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
$connection->execute('INSERT INTO users (name) VALUES (?)', ['Ada']);
$connection->execute('INSERT INTO users (name) VALUES (?)', ['Grace']);

// Build a portable SQL dump; quoteValue() is engine-aware string escaping.
$dump = "CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL);\n";
foreach ($connection->query('SELECT id, name FROM users') as $row) {
    $dump .= sprintf(
        "INSERT INTO users (id, name) VALUES (%d, %s);\n",
        $row['id'],
        $connection->quoteValue($row['name']),
    );
}

echo $dump;

// Drop and re-apply the dump as a crude import.
$connection->execute('DROP TABLE users');
foreach (array_filter(array_map('trim', explode(';', $dump))) as $statement) {
    $connection->execute($statement);
}
printf("imported=%d\n", count($connection->query('SELECT id FROM users')->fetchAll()));
