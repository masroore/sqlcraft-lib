<?php

declare(strict_types=1);

namespace SQLCraft\DTO;

final readonly class PartitionInfo
{
    public function __construct(
        public string $name,
        public ?string $schema,
        public string $method,
        public ?string $expression,
        public ?string $parentTable,
        public ?string $bound,
    ) {
    }
}
