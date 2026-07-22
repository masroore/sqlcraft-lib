<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Connection;

final readonly class ResultColumn
{
    public function __construct(
        public string $name,
        public ?string $nativeType,
        public ?string $table,
        public ?int $length,
        public bool $nullable,
    ) {}
}
