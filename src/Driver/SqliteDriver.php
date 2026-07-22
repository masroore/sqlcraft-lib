<?php

declare(strict_types=1);

namespace SQLCraft\Driver;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\PdoConnectionFactoryInterface;
use SQLCraft\Contracts\Driver\DriverInterface;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\ConnectionParameters;

final class SqliteDriver implements DriverInterface
{
    public function __construct(
        private readonly PdoConnectionFactoryInterface $connectionFactory,
        private readonly SqlitePlatform $platform,
    ) {}

    #[\Override]
    public function buildDsn(ConnectionParameters $params): string
    {
        return 'sqlite:'.($params->database ?? ':memory:');
    }

    #[\Override]
    public function connect(ConnectionParameters $params): ConnectionInterface
    {
        return $this->connectionFactory->connect($this->buildDsn($params), $params, $this->platform);
    }

    #[\Override]
    public function getPlatform(ConnectionInterface $connection): SqlitePlatform
    {
        return $this->platform;
    }

    #[\Override]
    public function getName(): string
    {
        return 'sqlite';
    }

    /** @return list<string> */
    #[\Override]
    public function getPdoDriverNames(): array
    {
        return ['sqlite'];
    }
}
