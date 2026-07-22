<?php

declare(strict_types=1);

namespace SQLCraft\DTO;

final readonly class DatabaseMeta
{
    public function __construct(
        public string $name,
        public ?string $charset,
        public ?string $collation,
    ) {}
}
