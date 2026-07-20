<?php

declare(strict_types=1);

namespace SQLCraft\DTO;

final readonly class IndexColumnMeta
{
    public function __construct(
        public string $columnName,
        public bool $descending,
        public ?int $length,
        public ?string $expression,
    ) {
    }
}
