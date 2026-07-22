<?php

declare(strict_types=1);

// Build CREATE TABLE with typed column defs, render SQL, then execute it.

require dirname(__DIR__) . '/vendor/autoload.php';

use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\DDL\CreateTableBuilder;
use SQLCraft\DDL\Definition\ColumnDefinition;
use SQLCraft\Driver\SqliteDriver;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\ConnectionParameters;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\DefaultValue;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

$platform = new SqlitePlatform;
$connection = (new SqliteDriver(
    new PdoConnectionFactory(new PdoExceptionTranslator),
    $platform,
))->connect(new ConnectionParameters(database: ':memory:'));

// Builders emit dialect SQL via the platform; they don't touch the connection.
$builder = (new CreateTableBuilder(new QualifiedName(new Identifier('events'))))
    ->withColumn(new ColumnDefinition(
        name: 'id',
        dataType: new DataType('INTEGER'),
        nullable: false,
        autoIncrement: false,
        primary: true,
        generated: false,
        default: DefaultValue::nullValue(),
        collation: null,
        comment: null,
        onUpdate: null,
        privileges: [],
        originalName: null,
        defaultConstraintName: null,
    ));

// toSql() can return multiple statements on engines that need multi-step DDL.
$sql = $builder->toSql($platform)[0];
echo $sql, PHP_EOL;
$connection->execute($sql);
