<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SQLCraft\Capabilities\Capability;
use SQLCraft\Capabilities\CapabilityNotSupportedException;
use SQLCraft\Capabilities\CapabilitySet;
use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Driver\SqliteDriver;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\ConnectionParameters;

$connectionFactory = new PdoConnectionFactory(new PdoExceptionTranslator);
$platform = new SqlitePlatform;
$driver = new SqliteDriver($connectionFactory, $platform);
$connection = $driver->connect(new ConnectionParameters(database: ':memory:'));

$capabilities = new CapabilitySet([
    Capability::Table,
    Capability::View,
    Capability::Indexes,
    Capability::ForeignKeys,
    Capability::CheckConstraints,
]);

printf("Has Table: %s\n", $capabilities->has(Capability::Table) ? 'yes' : 'no');
printf("Has Sequence: %s\n", $capabilities->has(Capability::Sequence) ? 'yes' : 'no');

try {
    $capabilities->require(Capability::Sequence);
    echo "Sequence capability available\n";
} catch (CapabilityNotSupportedException $e) {
    echo 'Exception: ', $e->getMessage(), PHP_EOL;
}

$connection->close();
