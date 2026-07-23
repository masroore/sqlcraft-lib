<?php

declare(strict_types=1);

namespace SQLCraft\Connection;

use InvalidArgumentException;
use SQLCraft\Contracts\Connection\CredentialProviderInterface;
use SQLCraft\ValueObjects\Credential;

final readonly class CredentialProviderChain implements CredentialProviderInterface
{
    /** @param list<CredentialProviderInterface> $providers */
    public function __construct(private array $providers)
    {
        if ($providers === []) {
            throw new InvalidArgumentException('Credential provider chain must contain at least one provider.');
        }
    }

    #[\Override]
    public function resolve(string $key): ?Credential
    {
        foreach ($this->providers as $provider) {
            $credential = $provider->resolve($key);
            if ($credential instanceof Credential) {
                return $credential;
            }
        }

        return null;
    }
}
