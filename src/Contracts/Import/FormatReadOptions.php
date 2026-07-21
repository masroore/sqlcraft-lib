<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Import;

final readonly class FormatReadOptions
{
    public function __construct(
        public string $separator = ',',
        public bool $hasHeader = true,
        public string $nullRepresentation = '\\N',
    ) {
        if ($separator === '') {
            throw new \InvalidArgumentException('Format separator cannot be empty.');
        }
        if ($nullRepresentation === '') {
            throw new \InvalidArgumentException('NULL representation cannot be empty.');
        }
    }
}
