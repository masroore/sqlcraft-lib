<?php

declare(strict_types=1);

namespace SQLCraft\Import;

final readonly class ImportResult
{
    /** @param list<ImportError> $errors */
    public function __construct(
        public int $statementsExecuted,
        public int $statementsSkipped,
        public array $errors,
        public float $elapsedMs,
    ) {
    }
}
