<?php

declare(strict_types=1);

namespace SQLCraft\DDL\Definition;

use SQLCraft\Contracts\DDL\CheckConstraintDefinitionInterface;
use SQLCraft\Contracts\DDL\ColumnDefinitionInterface;
use SQLCraft\Contracts\DDL\ForeignKeyDefinitionInterface;
use SQLCraft\Contracts\DDL\IndexDefinitionInterface;
use SQLCraft\Contracts\DDL\TableRecreationDefinitionInterface;
use SQLCraft\Contracts\DDL\TriggerDefinitionInterface;

final readonly class TableRecreationDefinition implements TableRecreationDefinitionInterface
{
    /**
     * @param list<ColumnDefinitionInterface> $columns
     * @param list<IndexDefinitionInterface> $indexes
     * @param list<ForeignKeyDefinitionInterface> $foreignKeys
     * @param list<CheckConstraintDefinitionInterface> $checkConstraints
     * @param list<TriggerDefinitionInterface> $triggers
     */
    public function __construct(
        private array $columns,
        private array $indexes = [],
        private array $foreignKeys = [],
        private array $checkConstraints = [],
        private array $triggers = [],
    ) {
    }

    /** @return list<ColumnDefinitionInterface> */
    #[\Override]
    public function getColumns(): array
    {
        return $this->columns;
    }
    /** @return list<IndexDefinitionInterface> */
    #[\Override]
    public function getIndexes(): array
    {
        return $this->indexes;
    }
    /** @return list<ForeignKeyDefinitionInterface> */
    #[\Override]
    public function getForeignKeys(): array
    {
        return $this->foreignKeys;
    }
    /** @return list<CheckConstraintDefinitionInterface> */
    #[\Override]
    public function getCheckConstraints(): array
    {
        return $this->checkConstraints;
    }
    /** @return list<TriggerDefinitionInterface> */
    #[\Override]
    public function getTriggers(): array
    {
        return $this->triggers;
    }
}
