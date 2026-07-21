<?php

declare(strict_types=1);

namespace SQLCraft\Connection;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\ConnectionManagerInterface;

final class ConnectionManager implements ConnectionManagerInterface
{
    /** @var array<string, ConnectionInterface> */
    private array $connections = [];

    #[\Override]
    public function get(string $name): ConnectionInterface
    {
        return $this->connections[$name] ?? throw new \InvalidArgumentException(sprintf('Connection not found: %s.', $name));
    }

    #[\Override]
    public function add(string $name, ConnectionInterface $connection): void
    {
        $this->connections[$name] = $connection;
    }

    #[\Override]
    public function closeAll(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        $this->connections = [];
    }
}
