<?php

declare(strict_types=1);

namespace SQLCraft\Connection;

use SQLCraft\Contracts\Connection\CredentialProviderInterface;
use SQLCraft\ValueObjects\Credential;

final readonly class ArrayCredentialProvider implements CredentialProviderInterface
{
    /** @param array<string, Credential> $credentials */
    public function __construct(private array $credentials)
    {
    }

    #[\Override]
    public function resolve(string $key): Credential
    {
        return $this->credentials[$key] ?? throw new \InvalidArgumentException(sprintf('Credential not found: %s.', $key));
    }
}
