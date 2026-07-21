<?php

declare(strict_types=1);

namespace SQLCraft\DDL\Definition;

use SQLCraft\Contracts\DDL\ColumnDefinitionInterface;
use SQLCraft\ValueObjects\Collation;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\DefaultValue;

final readonly class ColumnDefinition implements ColumnDefinitionInterface
{
    /** @param list<int> $privileges */
    public function __construct(
        private string $name,
        private DataType $dataType,
        private bool $nullable,
        private bool $autoIncrement,
        private bool $primary,
        private bool $generated,
        private DefaultValue $default,
        private ?Collation $collation,
        private ?string $comment,
        private ?string $onUpdate,
        private array $privileges,
        private ?string $originalName,
        private ?string $defaultConstraintName,
    ) {
    }

    #[\Override]
    public function getName(): string
    {
        return $this->name;
    }
    #[\Override]
    public function getDataType(): DataType
    {
        return $this->dataType;
    }
    #[\Override]
    public function isNullable(): bool
    {
        return $this->nullable;
    }
    #[\Override]
    public function isAutoIncrement(): bool
    {
        return $this->autoIncrement;
    }
    #[\Override]
    public function isPrimary(): bool
    {
        return $this->primary;
    }
    #[\Override]
    public function isGenerated(): bool
    {
        return $this->generated;
    }
    #[\Override]
    public function getDefault(): DefaultValue
    {
        return $this->default;
    }
    #[\Override]
    public function getCollation(): ?Collation
    {
        return $this->collation;
    }
    #[\Override]
    public function getComment(): ?string
    {
        return $this->comment;
    }
    #[\Override]
    public function getOnUpdate(): ?string
    {
        return $this->onUpdate;
    }
    /** @return list<int> */
    #[\Override]
    public function getPrivileges(): array
    {
        return $this->privileges;
    }
    #[\Override]
    public function getOriginalName(): ?string
    {
        return $this->originalName;
    }
    #[\Override]
    public function getDefaultConstraintName(): ?string
    {
        return $this->defaultConstraintName;
    }
}
