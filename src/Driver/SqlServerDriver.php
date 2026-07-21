<?php

declare(strict_types=1);

namespace SQLCraft\Driver;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\PdoConnectionFactoryInterface;
use SQLCraft\Contracts\Driver\DriverInterface;
use SQLCraft\Platform\SqlServerPlatform;
use SQLCraft\ValueObjects\ConnectionParameters;

final class SqlServerDriver implements DriverInterface
{
    public function __construct(
        private readonly PdoConnectionFactoryInterface $connectionFactory,
        private readonly SqlServerPlatform $platform,
    ) {
    }

    #[\Override]
    public function buildDsn(ConnectionParameters $params): string
    {
        $server = $params->socket ?? $params->host ?? '127.0.0.1';
        if ($params->port !== null) {
            $server .= ',' . $params->port;
        }

        $dsn = 'sqlsrv:Server=' . $server;
        if ($params->database !== null) {
            $dsn .= ';Database=' . $params->database;
        }
        return $dsn . ';TrustServerCertificate=Yes';
    }

    #[\Override]
    public function connect(ConnectionParameters $params): ConnectionInterface
    {
        return $this->connectionFactory->connect($this->buildDsn($params), $params, $this->platform);
    }

    #[\Override]
    public function getPlatform(ConnectionInterface $connection): SqlServerPlatform
    {
        return $this->platform;
    }

    #[\Override]
    public function getName(): string
    {
        return 'sqlserver';
    }

    /** @return list<string> */
    #[\Override]
    public function getPdoDriverNames(): array
    {
        return ['sqlsrv'];
    }
}
