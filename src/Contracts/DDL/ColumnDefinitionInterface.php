<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\DDL;

use SQLCraft\ValueObjects\Collation;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\DefaultValue;

interface ColumnDefinitionInterface
{
    public function getName(): string;

    public function getDataType(): DataType;

    public function isNullable(): bool;

    public function isAutoIncrement(): bool;

    public function isPrimary(): bool;

    public function isGenerated(): bool;

    public function getDefault(): DefaultValue;

    public function getCollation(): ?Collation;

    public function getComment(): ?string;

    public function getOnUpdate(): ?string;

    /** @return list<int> */
    public function getPrivileges(): array;

    public function getOriginalName(): ?string;

    public function getDefaultConstraintName(): ?string;
}
