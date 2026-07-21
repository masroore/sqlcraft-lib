<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Events;

use SQLCraft\Contracts\Connection\ConnectionInterface;

interface SchemaEventDispatcherInterface
{
    public function beforeDdlExecuted(ConnectionInterface $connection, string $sql, string $objectName): ?string;

    public function afterDdlExecuted(ConnectionInterface $connection, string $sql, string $objectName, float $elapsedMs): void;

    public function beforeSchemaChange(ConnectionInterface $connection, string $objectType, string $objectName, string $operation, string $sql): ?string;

    public function schemaChanged(ConnectionInterface $connection, string $objectType, string $objectName, string $operation): void;

    public function metadataFetched(ConnectionInterface $connection, string $objectType, string $objectName, float $elapsedMs): void;

    public function capabilityNotSupported(string $capability, string $platform, string $version): void;
}
