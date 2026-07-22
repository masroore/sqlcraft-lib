<?php

declare(strict_types=1);

namespace SQLCraft\Driver;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\PdoConnectionFactoryInterface;
use SQLCraft\Contracts\Driver\DriverInterface;
use SQLCraft\Platform\PostgreSQLPlatform;
use SQLCraft\ValueObjects\ConnectionParameters;

final class PostgreSQLDriver implements DriverInterface
{
    public function __construct(
        private readonly PdoConnectionFactoryInterface $connectionFactory,
        private readonly PostgreSQLPlatform $platform,
    ) {}

    #[\Override]
    public function buildDsn(ConnectionParameters $params): string
    {
        $dsn = 'pgsql:host='.($params->socket ?? $params->host ?? '127.0.0.1')
            .';port='.($params->port ?? 5432);
        if ($params->database !== null) {
            $dsn .= ';dbname='.$params->database;
        }

        return $dsn;
    }

    #[\Override]
    public function connect(ConnectionParameters $params): ConnectionInterface
    {
        return $this->connectionFactory->connect($this->buildDsn($params), $params, $this->platform);
    }

    #[\Override]
    public function getPlatform(ConnectionInterface $connection): PostgreSQLPlatform
    {
        return $this->platform;
    }

    #[\Override]
    public function getName(): string
    {
        return 'pgsql';
    }

    /** @return list<string> */
    #[\Override]
    public function getPdoDriverNames(): array
    {
        return ['pgsql'];
    }
}
