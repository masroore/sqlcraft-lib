<?php

declare(strict_types=1);

namespace SQLCraft\DDL\Definition;

use SQLCraft\Contracts\DDL\ForeignKeyDefinitionInterface;
use SQLCraft\ValueObjects\ForeignKeyAction;

final readonly class ForeignKeyDefinition implements ForeignKeyDefinitionInterface
{
    /**
     * @param list<string> $sourceColumns
     * @param list<string> $targetColumns
     */
    public function __construct(
        private string $constraintName,
        private ?string $targetDatabase,
        private ?string $targetSchema,
        private string $targetTable,
        private array $sourceColumns,
        private array $targetColumns,
        private ForeignKeyAction $onDelete,
        private ForeignKeyAction $onUpdate,
        private ?string $definition,
        private bool $deferrable = false,
    ) {
    }

    #[\Override]
    public function getConstraintName(): string
    {
        return $this->constraintName;
    }
    #[\Override]
    public function getTargetDatabase(): ?string
    {
        return $this->targetDatabase;
    }
    #[\Override]
    public function getTargetSchema(): ?string
    {
        return $this->targetSchema;
    }
    #[\Override]
    public function getTargetTable(): string
    {
        return $this->targetTable;
    }
    /** @return list<string> */
    #[\Override]
    public function getSourceColumns(): array
    {
        return $this->sourceColumns;
    }
    /** @return list<string> */
    #[\Override]
    public function getTargetColumns(): array
    {
        return $this->targetColumns;
    }
    #[\Override]
    public function getOnDelete(): ForeignKeyAction
    {
        return $this->onDelete;
    }
    #[\Override]
    public function getOnUpdate(): ForeignKeyAction
    {
        return $this->onUpdate;
    }
    #[\Override]
    public function getDefinition(): ?string
    {
        return $this->definition;
    }
    #[\Override]
    public function isDeferrable(): bool
    {
        return $this->deferrable;
    }
}
