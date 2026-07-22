<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Driver\SqliteDriver;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\ConnectionParameters;

$bindings = [];
$bindings['sqlcraft.connection'] = static function (): ConnectionInterface {
    $factory = new PdoConnectionFactory(new PdoExceptionTranslator);
    $driver = new SqliteDriver($factory, new SqlitePlatform);

    return $driver->connect(new ConnectionParameters(database: ':memory:'));
};

$connection = $bindings['sqlcraft.connection']();
$connection->execute('SELECT 1');
echo "Laravel provider binding resolved\n";
