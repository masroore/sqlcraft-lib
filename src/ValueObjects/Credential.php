<?php

declare(strict_types=1);

namespace SQLCraft\ValueObjects;

use SensitiveParameter;

final readonly class Credential
{
    public function __construct(
        public ?string $username = null,
        #[SensitiveParameter]
        public ?string $password = null,
    ) {
    }
}
