<?php

declare(strict_types=1);

namespace SQLCraft\Connection;

use SQLCraft\Contracts\Connection\CredentialProviderInterface;
use SQLCraft\ValueObjects\Credential;

final readonly class CallbackCredentialProvider implements CredentialProviderInterface
{
    /** @param \Closure(string): Credential $resolver */
    public function __construct(private \Closure $resolver)
    {
    }

    #[\Override]
    public function resolve(string $key): Credential
    {
        return ($this->resolver)($key);
    }
}
