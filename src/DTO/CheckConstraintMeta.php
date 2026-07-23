<?php

declare(strict_types=1);

namespace SQLCraft\DTO;

final readonly class CheckConstraintMeta
{
    public function __construct(
        public string $name,
        public string $expression,
        public bool $enforced,
    ) {
    }
}
