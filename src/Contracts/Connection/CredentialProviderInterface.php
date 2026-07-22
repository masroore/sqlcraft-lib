<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Connection;

use SQLCraft\ValueObjects\Credential;

interface CredentialProviderInterface
{
    public function resolve(string $key): ?Credential;
}
