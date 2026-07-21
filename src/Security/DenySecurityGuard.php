<?php

declare(strict_types=1);

namespace SQLCraft\Security;

use SQLCraft\Contracts\Security\SecurityGuardInterface;
use SQLCraft\Exceptions\InsufficientPrivilegesException;
use SQLCraft\ValueObjects\QualifiedName;

final readonly class DenySecurityGuard implements SecurityGuardInterface
{
    #[\Override]
    public function can(string $action, QualifiedName $object): bool
    {
        return false;
    }

    #[\Override]
    public function require(string $action, QualifiedName $object): void
    {
        throw new InsufficientPrivilegesException(sprintf('Privilege denied for %s on %s.', $action, $object->object->name));
    }
}
