<?php

declare(strict_types=1);

namespace SQLCraft\Driver;

use SQLCraft\Contracts\Driver\DriverInterface;
use SQLCraft\Contracts\Events\ConnectionEventDispatcherInterface;
use SQLCraft\Contracts\Execution\ProcessManagerFactoryInterface;
use SQLCraft\Contracts\Metadata\MetadataInspectorSetFactoryInterface;
use SQLCraft\Enums\DatabaseDriver;
use SQLCraft\Events\ConnectionEventDispatcher;
use SQLCraft\Events\SimpleEventDispatcher;
use SQLCraft\Events\SimpleListenerProvider;
use SQLCraft\Exceptions\DriverMisconfiguredException;
use SQLCraft\Exceptions\DriverNotFoundException;
use SQLCraft\Exceptions\DuplicateRegistrationException;
use SQLCraft\Exceptions\RegistrationNotFoundException;
use SQLCraft\Metadata\UnavailableMetadataInspectorSetFactory;
use SQLCraft\Support\ExtensionIdentifier;

final class DriverRegistry
{
    /** @var array<string, RegisteredDriver> */
    private array $drivers = [];

    /** @var array<string, string> */
    private array $aliases = [];

    /** @param iterable<DriverInterface|DriverDefinition|RegisteredDriver> $drivers */
    public function __construct(iterable $drivers = [])
    {
        foreach ($drivers as $driver) {
            $this->register($driver);
        }
    }

    public function register(
        DriverInterface|DriverDefinition|RegisteredDriver $definition,
        ?MetadataInspectorSetFactoryInterface $metadata = null,
        ?ProcessManagerFactoryInterface $processes = null,
    ): void {
        if ($definition instanceof DriverDefinition) {
            $name = ExtensionIdentifier::normalize($definition->name, 'driver');
            $driver = ($definition->driverFactory)(new ConnectionEventDispatcher(new SimpleEventDispatcher(new SimpleListenerProvider)));
            if ($driver->getName() !== $name) {
                throw new DriverMisconfiguredException(
                    sprintf('Driver factory returned %s for definition %s.', $driver->getName(), $name),
                    $name,
                );
            }
            $definition = new RegisteredDriver($driver, $definition->metadata, $definition->processes);
        } elseif ($definition instanceof DriverInterface) {
            $definition = new RegisteredDriver(
                $definition,
                $metadata ?? new UnavailableMetadataInspectorSetFactory,
                $processes,
            );
        }

        $name = ExtensionIdentifier::normalize($definition->driver->getName(), 'driver');
        if (isset($this->drivers[$name]) || isset($this->aliases[$name])) {
            throw new DuplicateRegistrationException(sprintf('Driver already registered: %s.', $name));
        }
        $this->drivers[$name] = $definition;
    }

    public function registerDefinition(DriverDefinition $definition, ConnectionEventDispatcherInterface $events): void
    {
        $name = ExtensionIdentifier::normalize($definition->name, 'driver');
        if (isset($this->drivers[$name]) || isset($this->aliases[$name])) {
            throw new DuplicateRegistrationException(sprintf('Driver already registered: %s.', $name));
        }

        $driver = ($definition->driverFactory)($events);
        if ($driver->getName() !== $name) {
            throw new DriverMisconfiguredException(
                sprintf('Driver factory returned %s for definition %s.', $driver->getName(), $name),
                $name,
            );
        }

        $this->drivers[$name] = new RegisteredDriver($driver, $definition->metadata, $definition->processes);
    }

    public function replace(DriverDefinition $definition, ConnectionEventDispatcherInterface $events): void
    {
        $name = ExtensionIdentifier::normalize($definition->name, 'driver');
        if (! isset($this->drivers[$name])) {
            throw new RegistrationNotFoundException(sprintf('Driver is not registered: %s.', $name));
        }

        $driver = ($definition->driverFactory)($events);
        if ($driver->getName() !== $name) {
            throw new DriverMisconfiguredException(
                sprintf('Driver factory returned %s for definition %s.', $driver->getName(), $name),
                $name,
            );
        }

        $this->drivers[$name] = new RegisteredDriver($driver, $definition->metadata, $definition->processes);
    }

    public function registerAlias(string $name, string $target): void
    {
        $name = ExtensionIdentifier::normalize($name, 'driver alias');
        if (isset($this->drivers[$name]) || isset($this->aliases[$name])) {
            throw new DuplicateRegistrationException(sprintf('Driver alias already registered: %s.', $name));
        }

        $target = ExtensionIdentifier::normalize($target, 'driver');
        if (! isset($this->drivers[$target])) {
            throw new RegistrationNotFoundException(sprintf('Driver alias target is not registered: %s.', $target));
        }

        $this->aliases[$name] = $target;
    }

    public function replaceAlias(string $name, string $target): void
    {
        $name = ExtensionIdentifier::normalize($name, 'driver alias');
        if (! isset($this->aliases[$name])) {
            throw new RegistrationNotFoundException(sprintf('Driver alias is not registered: %s.', $name));
        }

        $target = ExtensionIdentifier::normalize($target, 'driver');
        if (! isset($this->drivers[$target])) {
            throw new RegistrationNotFoundException(sprintf('Driver alias target is not registered: %s.', $target));
        }

        $this->aliases[$name] = $target;
    }

    public function get(string $name): DriverInterface
    {
        return $this->getRegistered($name)->driver;
    }

    public function getRegistered(string $name): RegisteredDriver
    {
        $name = ExtensionIdentifier::normalize($name, 'driver');
        $canonical = $this->aliases[$name] ?? $name;

        return $this->drivers[$canonical]
            ?? throw new DriverNotFoundException(sprintf('Driver not found: %s.', $name), $name);
    }

    public function getByDriver(string|DatabaseDriver $driver): DriverInterface
    {
        return $this->get($driver instanceof DatabaseDriver ? $driver->value : $driver);
    }

    /** @return list<string> */
    public function getRegisteredNames(): array
    {
        return [...array_keys($this->drivers), ...array_keys($this->aliases)];
    }

    /** @return array<string, string> */
    public function getAliases(): array
    {
        return $this->aliases;
    }
}
