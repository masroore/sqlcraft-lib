<?php

declare(strict_types=1);

namespace SQLCraft\DDL\Definition;

use SQLCraft\Contracts\DDL\IndexColumnDefinitionInterface;

final readonly class IndexColumnDefinition implements IndexColumnDefinitionInterface
{
    public function __construct(
        private string $columnName,
        private bool $descending,
        private ?int $length,
        private ?string $expression,
    ) {
    }

    #[\Override]
    public function getColumnName(): string
    {
        return $this->columnName;
    }

    #[\Override]
    public function isDescending(): bool
    {
        return $this->descending;
    }

    #[\Override]
    public function getLength(): ?int
    {
        return $this->length;
    }

    #[\Override]
    public function getExpression(): ?string
    {
        return $this->expression;
    }
}
