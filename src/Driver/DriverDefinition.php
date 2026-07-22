<?php
declare(strict_types=1);
namespace SQLCraft\Driver;
use SQLCraft\Contracts\Driver\DriverInterface;
use SQLCraft\Contracts\Events\ConnectionEventDispatcherInterface;
use SQLCraft\Contracts\Execution\ProcessManagerFactoryInterface;
use SQLCraft\Contracts\Metadata\MetadataInspectorSetFactoryInterface;
final readonly class DriverDefinition
{
    /** @param \Closure(ConnectionEventDispatcherInterface): DriverInterface $driverFactory */
    public function __construct(public string $name, public \Closure $driverFactory, public MetadataInspectorSetFactoryInterface $metadata, public ?ProcessManagerFactoryInterface $processes = null) {}
}
