<?php

declare(strict_types=1);

namespace SQLCraft\DTO;

use SQLCraft\ValueObjects\ForeignKeyAction;

final readonly class ForeignKeyMeta
{
    /**
     * @param  list<string>  $sourceColumns
     * @param  list<string>  $targetColumns
     */
    public function __construct(
        public string $constraintName,
        public ?string $targetDatabase,
        public ?string $targetSchema,
        public string $targetTable,
        public array $sourceColumns,
        public array $targetColumns,
        public ForeignKeyAction $onDelete,
        public ForeignKeyAction $onUpdate,
        public ?string $definition,
        public bool $deferrable = false,
    ) {
    }
}
