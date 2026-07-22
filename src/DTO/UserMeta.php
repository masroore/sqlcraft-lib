<?php

declare(strict_types=1);

namespace SQLCraft\DTO;

final readonly class UserMeta
{
    public function __construct(
        public string $name,
        public ?string $host,
        public ?string $plugin,
        public bool $superuser,
        public bool $canLogin,
    ) {}
}
