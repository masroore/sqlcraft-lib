<?php

declare(strict_types=1);

namespace SQLCraft\DTO;

final readonly class ViewMeta
{
    public function __construct(
        public string $name,
        public ?string $schema,
        public ?string $definition,
        public bool $materialized,
    ) {
    }
}
