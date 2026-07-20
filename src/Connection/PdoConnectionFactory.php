<?php

declare(strict_types=1);

namespace SQLCraft\Connection;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\PdoConnectionFactoryInterface;
use SQLCraft\Contracts\Driver\DriverInterface;
use SQLCraft\ValueObjects\ConnectionParameters;

/** @internal */
final class PdoConnectionFactory implements PdoConnectionFactoryInterface
{
    #[\Override]
    public function connect(
        DriverInterface $driver,
        ConnectionParameters $parameters,
    ): ConnectionInterface {
        return $driver->connect($parameters);
    }
}
