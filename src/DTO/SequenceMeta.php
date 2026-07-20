<?php

declare(strict_types=1);

namespace SQLCraft\DTO;

final readonly class SequenceMeta
{
    public function __construct(
        public string $name,
        public ?string $schema,
        public int|string $startValue,
        public int|string $minValue,
        public int|string $maxValue,
        public int $increment,
        public bool $cycle,
        public ?string $ownedByTable,
        public ?string $ownedByColumn,
    ) {
    }
}
