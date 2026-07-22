<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Driver\SqliteDriver;
use SQLCraft\Import\CsvImporter;
use SQLCraft\Import\CsvImportOptions;
use SQLCraft\Import\StringImportSource;
use SQLCraft\Metadata\ColumnInspector;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\Schema\SchemaManagerFactory;
use SQLCraft\ValueObjects\ConnectionParameters;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

$connectionFactory = new PdoConnectionFactory(new PdoExceptionTranslator);
$platform = new SqlitePlatform;
$driver = new SqliteDriver($connectionFactory, $platform);
$connection = $driver->connect(new ConnectionParameters(database: ':memory:'));

$connection->execute('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, price INTEGER)');

$csv = "id,name,price\n1,Laptop,999\n2,Mouse,25\n3,Keyboard,75\n";
$source = new StringImportSource($csv);
$factory = SchemaManagerFactory::metadataFactory($connection);
$columns = new ColumnInspector($factory);
$importer = new CsvImporter($columns);
$options = new CsvImportOptions(separator: ',', batchSize: 100);

$result = $importer->importCsv(
    $connection,
    new QualifiedName(new Identifier('products')),
    $source,
    $options,
);

printf("Imported %d rows in %.2fms\n", $result->statementsExecuted, $result->elapsedMs);
$connection->close();
