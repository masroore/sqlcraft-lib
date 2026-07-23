<?php

declare(strict_types=1);

namespace SQLCraft\DTO;

final readonly class BackwardKeyMeta
{
    /**
     * @param  list<string>  $sourceColumns
     * @param  list<string>  $targetColumns
     */
    public function __construct(
        public string $constraintName,
        public string $sourceTable,
        public array $sourceColumns,
        public array $targetColumns,
    ) {
    }
}
