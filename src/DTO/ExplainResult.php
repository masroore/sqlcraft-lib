<?php

declare(strict_types=1);

namespace SQLCraft\DTO;

final readonly class ExplainResult
{
    /**
     * @param  list<array<string, int|float|string|bool|null>>  $rows
     * @param  array<string, int|float|string|bool|null>|null  $json
     */
    public function __construct(
        public string $engine,
        public array $rows,
        public ?string $tree = null,
        public ?array $json = null,
        public float $elapsedMs = 0.0,
    ) {}
}
