<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Driver;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\ValueObjects\ConnectionParameters;

interface DriverInterface
{
    public function buildDsn(ConnectionParameters $params): string;

    public function connect(ConnectionParameters $params, ?string $name = null): ConnectionInterface;

    public function getPlatform(ConnectionInterface $connection): PlatformInterface;

    public function getName(): string;

    /** @return list<string> */
    public function getPdoDriverNames(): array;
}
