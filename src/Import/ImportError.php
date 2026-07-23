<?php

declare(strict_types=1);

namespace SQLCraft\Import;

final readonly class ImportError
{
    public function __construct(
        public int $statementIndex,
        public string $sql,
        public string $errorMessage,
        public int $errorCode,
    ) {
    }
}
