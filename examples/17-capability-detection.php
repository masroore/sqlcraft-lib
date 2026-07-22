<?php

declare(strict_types=1);

// Feature-detect before using engine-specific APIs; require() throws if missing.

require dirname(__DIR__) . '/vendor/autoload.php';

use SQLCraft\Capabilities\Capability;
use SQLCraft\Capabilities\CapabilityNotSupportedException;
use SQLCraft\Capabilities\CapabilitySet;
use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Driver\SqliteDriver;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\ConnectionParameters;

$connection = (new SqliteDriver(
    new PdoConnectionFactory(new PdoExceptionTranslator),
    new SqlitePlatform,
))->connect(new ConnectionParameters(database: ':memory:'));

// In real code you'd take this from the platform; here we fix a set for clarity.
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
    // Prefer require() at API boundaries so missing features fail loudly.
    $capabilities->require(Capability::Sequence);
    echo "Sequence capability available\n";
} catch (CapabilityNotSupportedException $e) {
    echo 'Exception: ', $e->getMessage(), PHP_EOL;
}

$connection->close();
