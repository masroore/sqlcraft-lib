<?php

declare(strict_types=1);

namespace SQLCraft\Security;

use SQLCraft\Capabilities\Capability;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Execution\QueryExecutorInterface;
use SQLCraft\Contracts\Security\UserManagerInterface;

final readonly class UserManager implements UserManagerInterface
{
    public function __construct(private ConnectionInterface $connection, private QueryExecutorInterface $executor)
    {
    }

    #[\Override]
    public function createUser(string $username, #[\SensitiveParameter] string $password): void
    {
        $this->execute($this->connection->getPlatformName() === 'sqlserver'
            ? 'CREATE LOGIN ' . $this->id($username) . ' WITH PASSWORD = ?'
            : 'CREATE USER ' . $this->literal($username) . ' IDENTIFIED BY ?');
    }

    #[\Override]
    public function alterUser(string $username, #[\SensitiveParameter] string $password): void
    {
        $this->execute($this->connection->getPlatformName() === 'sqlserver'
            ? 'ALTER LOGIN ' . $this->id($username) . ' WITH PASSWORD = ?'
            : 'ALTER USER ' . $this->literal($username) . ' IDENTIFIED BY ?');
    }

    #[\Override]
    public function dropUser(string $username): void
    {
        $this->execute('DROP USER ' . $this->literal($username));
    }

    #[\Override]
    public function createRole(string $role): void
    {
        $this->execute('CREATE ROLE ' . $this->id($role));
    }

    #[\Override]
    public function dropRole(string $role): void
    {
        $this->execute('DROP ROLE ' . $this->id($role));
    }

    private function execute(string $sql): void
    {
        $this->connection->getPlatform()->getCapabilitySet($this->connection->getServerVersion())->require(Capability::Privileges);
        $this->executor->executeDdl($this->connection, $sql);
    }

    private function literal(string $value): string
    {
        return $this->connection->quoteValue($value);
    }

    private function id(string $value): string
    {
        return $this->connection->quoteIdentifier($value);
    }
}
