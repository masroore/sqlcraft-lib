<?php

declare(strict_types=1);

namespace SQLCraft\ValueObjects;

final readonly class DataType
{
    public function __construct(
        public string $name,
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
        public bool $unsigned = false,
        public ?string $collation = null,
        public ?string $charset = null,
    ) {
    }
}
