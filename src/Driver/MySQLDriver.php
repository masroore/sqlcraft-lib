<?php

declare(strict_types=1);

namespace SQLCraft\Driver;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\PdoConnectionFactoryInterface;
use SQLCraft\Contracts\Driver\DriverInterface;
use SQLCraft\Platform\MySQLPlatform;
use SQLCraft\ValueObjects\ConnectionParameters;

final class MySQLDriver implements DriverInterface
{
    public function __construct(
        private readonly PdoConnectionFactoryInterface $connectionFactory,
        private readonly MySQLPlatform $platform,
    ) {
    }

    #[\Override]
    public function buildDsn(ConnectionParameters $params): string
    {
        $dsn = $params->socket === null
            ? 'mysql:host=' . ($params->host ?? '127.0.0.1') . ';port=' . ($params->port ?? 3306)
            : 'mysql:unix_socket=' . $params->socket;
        if ($params->database !== null) {
            $dsn .= ';dbname=' . $params->database;
        }
        if ($params->charset !== null) {
            $dsn .= ';charset=' . $params->charset;
        }

        return $dsn;
    }

    #[\Override]
    public function connect(ConnectionParameters $params): ConnectionInterface
    {
        return $this->connectionFactory->connect($this->buildDsn($params), $params, $this->platform);
    }

    #[\Override]
    public function getPlatform(ConnectionInterface $connection): MySQLPlatform
    {
        return $this->platform;
    }

    #[\Override]
    public function getName(): string
    {
        return 'mysql';
    }

    /** @return list<string> */
    #[\Override]
    public function getPdoDriverNames(): array
    {
        return ['mysql'];
    }
}
