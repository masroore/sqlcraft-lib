<?php

declare(strict_types=1);

namespace SQLCraft\Import;

use InvalidArgumentException;

final readonly class ImportOptions
{
    public function __construct(
        public bool $stopOnError = true,
        public bool $wrapInTransaction = false,
        public int $progressInterval = 50,
        public int $statementTimeoutMs = 0,
        public ?int $maxStatements = 10000,
    ) {
        if ($progressInterval < 1) {
            throw new InvalidArgumentException('Progress interval must be >= 1.');
        }
        if ($statementTimeoutMs < 0) {
            throw new InvalidArgumentException('Statement timeout must be >= 0.');
        }
        if ($maxStatements !== null && $maxStatements < 1) {
            throw new InvalidArgumentException('Maximum statements must be >= 1.');
        }
    }
}
