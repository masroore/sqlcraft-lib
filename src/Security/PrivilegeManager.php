<?php

declare(strict_types=1);

namespace SQLCraft\Security;

use SQLCraft\Capabilities\Capability;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Execution\QueryExecutorInterface;
use SQLCraft\Contracts\Security\PrivilegeManagerInterface;
use SQLCraft\ValueObjects\Privilege;
use SQLCraft\ValueObjects\QualifiedName;

final readonly class PrivilegeManager implements PrivilegeManagerInterface
{
    public function __construct(private ConnectionInterface $connection, private QueryExecutorInterface $executor)
    {
    }

    #[\Override]
    public function grant(Privilege $privilege, QualifiedName $object, string $grantee): void
    {
        $this->execute('GRANT ' . $privilege->name . ' ON ' . $this->object($object) . ' TO ' . $this->connection->quoteValue($grantee));
    }

    #[\Override]
    public function revoke(Privilege $privilege, QualifiedName $object, string $grantee): void
    {
        $this->execute('REVOKE ' . $privilege->name . ' ON ' . $this->object($object) . ' FROM ' . $this->connection->quoteValue($grantee));
    }

    private function execute(string $sql): void
    {
        $this->connection->getPlatform()->getCapabilitySet($this->connection->getServerVersion())->require(Capability::Privileges);
        $this->executor->executeDdl($this->connection, $sql);
    }

    private function object(QualifiedName $name): string
    {
        $parts = [];
        if ($name->schema !== null) {
            $parts[] = $this->connection->quoteIdentifier($name->schema->name);
        }
        $parts[] = $this->connection->quoteIdentifier($name->object->name);

        return implode('.', $parts);
    }
}
