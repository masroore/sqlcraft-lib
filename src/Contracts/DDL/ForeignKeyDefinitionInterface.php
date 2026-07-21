<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\DDL;

use SQLCraft\ValueObjects\ForeignKeyAction;

interface ForeignKeyDefinitionInterface
{
    public function getConstraintName(): string;

    public function getTargetDatabase(): ?string;

    public function getTargetSchema(): ?string;

    public function getTargetTable(): string;

    /** @return list<string> */
    public function getSourceColumns(): array;

    /** @return list<string> */
    public function getTargetColumns(): array;

    public function getOnDelete(): ForeignKeyAction;

    public function getOnUpdate(): ForeignKeyAction;

    public function getDefinition(): ?string;

    public function isDeferrable(): bool;
}
