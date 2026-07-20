<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Connection;

use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\ValueObjects\ConnectionParameters;

interface PdoConnectionFactoryInterface
{
    public function connect(
        string $dsn,
        ConnectionParameters $parameters,
        PlatformInterface $platform,
        ?string $name = null,
    ): ConnectionInterface;
}
