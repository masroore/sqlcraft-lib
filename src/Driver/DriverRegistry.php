<?php

declare(strict_types=1);

namespace SQLCraft\Driver;

use SQLCraft\Contracts\Driver\DriverInterface;
use SQLCraft\Exceptions\DriverNotFoundException;

final class DriverRegistry
{
    /** @var array<string, DriverInterface> */
    private array $drivers = [];

    /** @param iterable<DriverInterface> $drivers */
    public function __construct(iterable $drivers = [])
    {
        foreach ($drivers as $driver) {
            $this->register($driver);
        }
    }

    public function register(DriverInterface $driver): void
    {
        $this->drivers[$driver->getName()] = $driver;
    }

    public function get(string $name): DriverInterface
    {
        return $this->drivers[$name]
            ?? throw new DriverNotFoundException(sprintf('Driver not found: %s.', $name), $name);
    }

    /** @return list<string> */
    public function getRegisteredNames(): array
    {
        return array_keys($this->drivers);
    }
}
