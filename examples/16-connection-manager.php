<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SQLCraft\Connection\ConnectionManager;
use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Driver\SqliteDriver;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\ConnectionParameters;

$connectionFactory = new PdoConnectionFactory(new PdoExceptionTranslator);
$platform = new SqlitePlatform;
$driver = new SqliteDriver($connectionFactory, $platform);

$manager = new ConnectionManager;
$manager->add('main', $driver->connect(new ConnectionParameters(database: ':memory:')));
$manager->add('cache', $driver->connect(new ConnectionParameters(database: ':memory:')));

$mainConn = $manager->get('main');
$mainConn->execute('CREATE TABLE users (id INTEGER)');
$mainConn->execute('INSERT INTO users VALUES (1)');
printf("Main: %d rows\n", count($mainConn->query('SELECT * FROM users')->fetchAll()));

$cacheConn = $manager->get('cache');
$cacheConn->execute('CREATE TABLE sessions (token TEXT)');
$cacheConn->execute('INSERT INTO sessions VALUES (?)', ['abc123']);
printf("Cache: %d rows\n", count($cacheConn->query('SELECT * FROM sessions')->fetchAll()));

$manager->closeAll();
echo "All connections closed\n";
