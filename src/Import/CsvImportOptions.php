<?php

declare(strict_types=1);

namespace SQLCraft\Import;

use InvalidArgumentException;

final readonly class CsvImportOptions
{
    public function __construct(
        public string $separator = ',',
        public string $nullRepresentation = '\\N',
        public UpsertMode $upsertMode = UpsertMode::Insert,
        public bool $wrapInTransaction = true,
        public int $batchSize = 100,
        public int $statementTimeoutMs = 0,
    ) {
        if ($separator === '') {
            throw new InvalidArgumentException('CSV separator cannot be empty.');
        }
        if ($nullRepresentation === '') {
            throw new InvalidArgumentException('NULL representation cannot be empty.');
        }
        if ($batchSize < 1) {
            throw new InvalidArgumentException('CSV batch size must be >= 1.');
        }
        if ($statementTimeoutMs < 0) {
            throw new InvalidArgumentException('Statement timeout must be >= 0.');
        }
    }
}
