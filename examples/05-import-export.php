<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Driver\SqliteDriver;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\ConnectionParameters;

$connectionFactory = new PdoConnectionFactory(new PdoExceptionTranslator);
$platform = new SqlitePlatform;
$driver = new SqliteDriver($connectionFactory, $platform);
$connection = $driver->connect(new ConnectionParameters(database: ':memory:'));

$connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
$connection->execute('INSERT INTO users (name) VALUES (?)', ['Ada']);
$connection->execute('INSERT INTO users (name) VALUES (?)', ['Grace']);

$dump = "CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL);\n";
$rows = $connection->query('SELECT id, name FROM users');
foreach ($rows as $row) {
    $dump .= sprintf(
        "INSERT INTO users (id, name) VALUES (%d, %s);\n",
        $row['id'],
        $connection->quoteValue($row['name']),
    );
}

echo $dump;
$connection->execute('DROP TABLE users');
foreach (array_filter(array_map('trim', explode(';', $dump))) as $statement) {
    $connection->execute($statement);
}
printf("imported=%d\n", count($connection->query('SELECT id FROM users')->fetchAll()));
