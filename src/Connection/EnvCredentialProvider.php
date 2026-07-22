<?php

declare(strict_types=1);

namespace SQLCraft\Connection;

use SQLCraft\Contracts\Connection\CredentialProviderInterface;
use SQLCraft\ValueObjects\Credential;

final readonly class EnvCredentialProvider implements CredentialProviderInterface
{
    public function __construct(private string $prefix = 'SQLCRAFT_') {}

    #[\Override]
    public function resolve(string $key): Credential
    {
        $name = strtoupper(preg_replace('/[^A-Z0-9]+/i', '_', $key) ?? $key);
        $username = getenv($this->prefix . $name . '_USERNAME');
        $password = getenv($this->prefix . $name . '_PASSWORD');

        return new Credential(
            $username === false ? null : $username,
            $password === false ? null : $password,
        );
    }
}
