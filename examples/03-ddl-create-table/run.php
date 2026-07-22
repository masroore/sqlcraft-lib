<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

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

$connectionFactory = new PdoConnectionFactory(new PdoExceptionTranslator);
$platform = new SqlitePlatform;
$driver = new SqliteDriver($connectionFactory, $platform);
$connection = $driver->connect(new ConnectionParameters(database: ':memory:'));

$table = new QualifiedName(new Identifier('events'));
$builder = (new CreateTableBuilder($table))->withColumn(new ColumnDefinition(
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

$sql = $builder->toSql($platform)[0];
echo $sql, PHP_EOL;
$connection->execute($sql);
