<?php

declare(strict_types=1);

// Shape only: bind SQLCraft connection like a Laravel container closure.

require dirname(__DIR__) . '/vendor/autoload.php';

use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Driver\SqliteDriver;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\ConnectionParameters;

// Mimics $this->app->singleton('sqlcraft.connection', fn () => …).
$bindings = [];
$bindings['sqlcraft.connection'] = static function (): ConnectionInterface {
    return (new SqliteDriver(
        new PdoConnectionFactory(new PdoExceptionTranslator),
        new SqlitePlatform,
    ))->connect(new ConnectionParameters(database: ':memory:'));
};

$connection = $bindings['sqlcraft.connection']();
$connection->execute('SELECT 1');
echo "Laravel provider binding resolved\n";
