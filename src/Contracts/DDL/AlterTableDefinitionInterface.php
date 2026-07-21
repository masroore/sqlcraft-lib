<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\DDL;

use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

interface AlterTableDefinitionInterface
{
    public function getTable(): QualifiedName;

    /** @return list<array{0: ColumnDefinitionInterface, 1: ?Identifier}> */
    public function getAddColumns(): array;

    /** @return list<array{0: ColumnDefinitionInterface, 1: ColumnDefinitionInterface}> */
    public function getModifyColumns(): array;

    /** @return list<Identifier> */
    public function getDropColumns(): array;

    /** @return list<IndexDefinitionInterface> */
    public function getAddIndexes(): array;

    /** @return list<Identifier> */
    public function getDropIndexes(): array;

    /** @return list<ForeignKeyDefinitionInterface> */
    public function getAddForeignKeys(): array;

    /** @return list<Identifier> */
    public function getDropForeignKeys(): array;

    /** @return list<CheckConstraintDefinitionInterface> */
    public function getAddCheckConstraints(): array;

    /** @return list<Identifier> */
    public function getDropCheckConstraints(): array;

    public function getRename(): ?Identifier;
}
