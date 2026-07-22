<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\DDL\CreateIndexBuilder;
use SQLCraft\DDL\Definition\IndexColumnDefinition;
use SQLCraft\DDL\Definition\IndexDefinition;
use SQLCraft\DDL\DropIndexBuilder;
use SQLCraft\Driver\SqliteDriver;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\ConnectionParameters;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\IndexType;
use SQLCraft\ValueObjects\QualifiedName;

$connectionFactory = new PdoConnectionFactory(new PdoExceptionTranslator);
$platform = new SqlitePlatform;
$driver = new SqliteDriver($connectionFactory, $platform);
$connection = $driver->connect(new ConnectionParameters(database: ':memory:'));

$connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)');

$table = new QualifiedName(new Identifier('users'));
$index = new IndexDefinition(
    name: 'idx_users_email',
    type: IndexType::INDEX,
    columns: [new IndexColumnDefinition('email', false, null, null)],
    unique: false,
    comment: null,
    algorithm: null,
    filterExpression: null,
);

$createBuilder = new CreateIndexBuilder($table, $index);
$sql = $createBuilder->toSql($platform)[0];
echo $sql, PHP_EOL;
$connection->execute($sql);

$dropBuilder = new DropIndexBuilder($table, new Identifier('idx_users_email'));
$sql = $dropBuilder->toSql($platform)[0];
echo $sql, PHP_EOL;
$connection->execute($sql);

$connection->close();
