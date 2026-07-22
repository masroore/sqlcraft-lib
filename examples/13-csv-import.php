<?php

declare(strict_types=1);

// Stream CSV text into an existing table (header row = column names).

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

$connection = (new SqliteDriver(
    new PdoConnectionFactory(new PdoExceptionTranslator),
    new SqlitePlatform,
))->connect(new ConnectionParameters(database: ':memory:'));

$connection->execute('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, price INTEGER)');

// StringImportSource is for demos; file/stream sources work the same way.
$csv = "id,name,price\n1,Laptop,999\n2,Mouse,25\n3,Keyboard,75\n";
$importer = new CsvImporter(
    new ColumnInspector(SchemaManagerFactory::metadataFactory($connection)),
);

$result = $importer->importCsv(
    $connection,
    new QualifiedName(new Identifier('products')),
    new StringImportSource($csv),
    new CsvImportOptions(separator: ',', batchSize: 100),
);

printf("Imported %d rows in %.2fms\n", $result->statementsExecuted, $result->elapsedMs);
$connection->close();
