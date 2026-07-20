<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Connection;

use SQLCraft\Contracts\Driver\DriverInterface;
use SQLCraft\ValueObjects\ConnectionParameters;

interface PdoConnectionFactoryInterface
{
    public function connect(
        DriverInterface $driver,
        ConnectionParameters $parameters,
    ): ConnectionInterface;
}
