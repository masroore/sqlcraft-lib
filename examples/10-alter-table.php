<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\DDL\AlterTableBuilder;
use SQLCraft\DDL\Definition\ColumnDefinition;
use SQLCraft\Driver\SqliteDriver;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\ConnectionParameters;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\DefaultValue;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

$connectionFactory = new PdoConnectionFactory(new PdoExceptionTranslator);
$platform = new SqlitePlatform;
$driver = new SqliteDriver($connectionFactory, $platform);
$connection = $driver->connect(new ConnectionParameters(database: ':memory:'));

$connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');

$table = new QualifiedName(new Identifier('users'));
$builder = (new AlterTableBuilder($table))
    ->withColumn(new ColumnDefinition(
        name: 'email',
        dataType: new DataType('TEXT'),
        nullable: true,
        autoIncrement: false,
        primary: false,
        generated: false,
        default: DefaultValue::nullValue(),
        collation: null,
        comment: null,
        onUpdate: null,
        privileges: [],
        originalName: null,
        defaultConstraintName: null,
    ))
    ->renameTo(new Identifier('accounts'));

foreach ($builder->toSql($platform) as $sql) {
    echo $sql, PHP_EOL;
    $connection->execute($sql);
}

$connection->close();
