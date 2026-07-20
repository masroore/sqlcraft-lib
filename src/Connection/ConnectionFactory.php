<?php

declare(strict_types=1);

namespace SQLCraft\Connection;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\PdoConnectionFactoryInterface;
use SQLCraft\Contracts\Driver\DriverInterface;
use SQLCraft\ValueObjects\ConnectionParameters;

/** @internal */
final class ConnectionFactory
{
    public function __construct(
        private readonly PdoConnectionFactoryInterface $pdoFactory,
        private readonly DriverInterface $driver,
    ) {
    }

    public function connect(ConnectionParameters $parameters): ConnectionInterface
    {
        return $this->pdoFactory->connect($this->driver, $parameters);
    }
}
