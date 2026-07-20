<?php

declare(strict_types=1);

namespace SQLCraft\Import;

final readonly class ImportOptions
{
    public function __construct(
        public bool $stopOnError = true,
        public bool $wrapInTransaction = false,
        public int $progressInterval = 50,
        public int $statementTimeoutMs = 0,
        public ?int $maxStatements = null,
    ) {
    }
}
