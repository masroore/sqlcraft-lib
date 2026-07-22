<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Security;

use SQLCraft\ValueObjects\Privilege;
use SQLCraft\ValueObjects\QualifiedName;

interface PrivilegeManagerInterface
{
    public function grant(Privilege $privilege, QualifiedName $object, string $grantee): void;

    public function revoke(Privilege $privilege, QualifiedName $object, string $grantee): void;
}
