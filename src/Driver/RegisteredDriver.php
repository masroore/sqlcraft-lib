<?php
declare(strict_types=1);
namespace SQLCraft\Driver;
use SQLCraft\Contracts\Driver\DriverInterface;
use SQLCraft\Contracts\Execution\ProcessManagerFactoryInterface;
use SQLCraft\Contracts\Metadata\MetadataInspectorSetFactoryInterface;
/** @internal */
final readonly class RegisteredDriver
{
    public function __construct(public DriverInterface $driver, public MetadataInspectorSetFactoryInterface $metadata, public ?ProcessManagerFactoryInterface $processes = null) {}
}
