<?php

declare(strict_types=1);

namespace SQLCraft\DTO;

final readonly class QueryWarning
{
    public function __construct(
        public string $level,
        public int $code,
        public string $message,
    ) {}
}
