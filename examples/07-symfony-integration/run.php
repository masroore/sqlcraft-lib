<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Driver\SqliteDriver;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\ConnectionParameters;

$serviceDefinition = [
    'class' => ConnectionInterface::class,
    'factory' => static function (): ConnectionInterface {
        $factory = new PdoConnectionFactory(new PdoExceptionTranslator);
        $driver = new SqliteDriver($factory, new SqlitePlatform);

        return $driver->connect(new ConnectionParameters(database: ':memory:'));
    },
];

$connection = $serviceDefinition['factory']();
$connection->execute('SELECT 1');
echo "Symfony service definition resolved\n";
