<?php

declare(strict_types=1);

// Shape only: factory-style service definition for a DI container.

require dirname(__DIR__) . '/vendor/autoload.php';

use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Driver\SqliteDriver;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\ConnectionParameters;

// Mimics services.yaml: class + factory callable.
$serviceDefinition = [
    'class' => ConnectionInterface::class,
    'factory' => static function (): ConnectionInterface {
        return (new SqliteDriver(
            new PdoConnectionFactory(new PdoExceptionTranslator),
            new SqlitePlatform,
        ))->connect(new ConnectionParameters(database: ':memory:'));
    },
];

$connection = $serviceDefinition['factory']();
$connection->execute('SELECT 1');
echo "Symfony service definition resolved\n";
