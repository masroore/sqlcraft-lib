<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Metadata;

use SQLCraft\Collections\PrivilegeCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\ValueObjects\QualifiedName;

interface PrivilegeInspectorInterface
{
    public function getPrivileges(
        ConnectionInterface $conn,
        ?string $user = null,
        ?QualifiedName $object = null,
    ): PrivilegeCollection;
}
