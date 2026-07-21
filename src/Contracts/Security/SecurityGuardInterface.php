<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Security;

use SQLCraft\ValueObjects\QualifiedName;

interface SecurityGuardInterface
{
    public function can(string $action, QualifiedName $object): bool;

    public function require(string $action, QualifiedName $object): void;
}
