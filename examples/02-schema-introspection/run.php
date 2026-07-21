<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Driver\SqliteDriver;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\Schema\SchemaManagerFactory;
use SQLCraft\ValueObjects\ConnectionParameters;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;


$connectionFactory = new PdoConnectionFactory(new PdoExceptionTranslator());
$platform = new SqlitePlatform();
$driver = new SqliteDriver($connectionFactory, $platform);
$connection = $driver->connect(new ConnectionParameters(database: ':memory:'));

$connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
$schema = SchemaManagerFactory::forConnection($connection);

foreach ($schema->getTables($connection) as $table) {
    echo $table->name, PHP_EOL;
}

$structure = $schema->describeTable($connection, new QualifiedName(new Identifier('users')));
printf("columns=%d\n", count($structure->columns));
