<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\DDL;

interface TableRecreationDefinitionInterface
{
    /** @return list<ColumnDefinitionInterface> */
    public function getColumns(): array;

    /** @return list<IndexDefinitionInterface> */
    public function getIndexes(): array;

    /** @return list<ForeignKeyDefinitionInterface> */
    public function getForeignKeys(): array;

    /** @return list<CheckConstraintDefinitionInterface> */
    public function getCheckConstraints(): array;

    /** @return list<TriggerDefinitionInterface> */
    public function getTriggers(): array;
}
