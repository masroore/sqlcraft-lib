<?php

declare(strict_types=1);

namespace SQLCraft\Events;

use Psr\EventDispatcher\EventDispatcherInterface;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Events\SchemaEventDispatcherInterface;

final readonly class SchemaEventDispatcher implements SchemaEventDispatcherInterface
{
    public function __construct(private EventDispatcherInterface $dispatcher) {}

    #[\Override]
    public function beforeDdlExecuted(ConnectionInterface $connection, string $sql, string $objectName): ?string
    {
        $event = new BeforeDdlExecuted($connection, $sql, $objectName);
        $this->dispatcher->dispatch($event);

        return $event->isCancelled() ? ($event->cancelReason === '' ? 'DDL execution was cancelled.' : $event->cancelReason) : null;
    }

    #[\Override]
    public function afterDdlExecuted(ConnectionInterface $connection, string $sql, string $objectName, float $elapsedMs): void
    {
        $this->dispatcher->dispatch(new AfterDdlExecuted($connection, $sql, $objectName, $elapsedMs));
    }

    #[\Override]
    public function beforeSchemaChange(ConnectionInterface $connection, string $objectType, string $objectName, string $operation, string $sql): ?string
    {
        $event = new BeforeSchemaChange($connection, $objectType, $objectName, $operation, $sql);
        $this->dispatcher->dispatch($event);

        return $event->isCancelled() ? ($event->cancelReason === '' ? 'Schema change was cancelled.' : $event->cancelReason) : null;
    }

    #[\Override]
    public function schemaChanged(ConnectionInterface $connection, string $objectType, string $objectName, string $operation): void
    {
        $this->dispatcher->dispatch(new SchemaChangedEvent($connection, $objectType, $objectName, $operation));
    }

    #[\Override]
    public function metadataFetched(ConnectionInterface $connection, string $objectType, string $objectName, float $elapsedMs): void
    {
        $this->dispatcher->dispatch(new MetadataFetchedEvent($connection, $objectType, $objectName, $elapsedMs));
    }

    #[\Override]
    public function capabilityNotSupported(string $capability, string $platform, string $version): void
    {
        $this->dispatcher->dispatch(new CapabilityNotSupportedEvent($capability, $platform, $version));
    }
}
