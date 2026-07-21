<?php

declare(strict_types=1);

namespace SQLCraft\Security;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Metadata\PrivilegeInspectorInterface;
use SQLCraft\Contracts\Security\SecurityGuardInterface;
use SQLCraft\Exceptions\InsufficientPrivilegesException;
use SQLCraft\ValueObjects\QualifiedName;

final readonly class PrivilegeGuard implements SecurityGuardInterface
{
    public function __construct(
        private ConnectionInterface $connection,
        private PrivilegeInspectorInterface $inspector,
        private ?string $user = null,
    ) {
    }

    #[\Override]
    public function can(string $action, QualifiedName $object): bool
    {
        foreach ($this->inspector->getPrivileges($this->connection, $this->user, $object) as $privilege) {
            if (strcasecmp($privilege->name, $action) === 0 || $privilege->name === 'ALL PRIVILEGES') {
                return true;
            }
        }

        return false;
    }

    #[\Override]
    public function require(string $action, QualifiedName $object): void
    {
        if (!$this->can($action, $object)) {
            throw new InsufficientPrivilegesException(
                sprintf('Privilege %s denied on %s.', $action, $object->object->name),
                privilege: $action,
                object: $object->object->name,
            );
        }
    }
}
