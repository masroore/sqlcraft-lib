<?php

declare(strict_types=1);

// List tables and describe one via SchemaManager (metadata, not raw SQL).

require dirname(__DIR__) . '/vendor/autoload.php';

use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Driver\SqliteDriver;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\Schema\SchemaManagerFactory;
use SQLCraft\ValueObjects\ConnectionParameters;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

$connection = (new SqliteDriver(
    new PdoConnectionFactory(new PdoExceptionTranslator),
    new SqlitePlatform,
))->connect(new ConnectionParameters(database: ':memory:'));

$connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');

// Factory picks the engine-specific inspectors for this connection.
$schema = SchemaManagerFactory::forConnection($connection);

foreach ($schema->getTables($connection) as $table) {
    echo $table->name, PHP_EOL;
}

// QualifiedName is the typed table identity used across schema/DDL APIs.
$structure = $schema->describeTable($connection, new QualifiedName(new Identifier('users')));
printf("columns=%d\n", count($structure->columns));
