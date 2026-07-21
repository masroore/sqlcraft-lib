<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Security;

interface UserManagerInterface
{
    public function createUser(string $username, #[\SensitiveParameter] string $password): void;
    public function alterUser(string $username, #[\SensitiveParameter] string $password): void;
    public function dropUser(string $username): void;
    public function createRole(string $role): void;
    public function dropRole(string $role): void;
}
