<?php

declare(strict_types=1);

namespace SQLCraft\DDL\Definition;

use SQLCraft\Contracts\DDL\IndexColumnDefinitionInterface;
use SQLCraft\Contracts\DDL\IndexDefinitionInterface;
use SQLCraft\ValueObjects\IndexType;

final readonly class IndexDefinition implements IndexDefinitionInterface
{
    /** @param list<IndexColumnDefinitionInterface> $columns */
    public function __construct(
        private string $name,
        private IndexType $type,
        private array $columns,
        private bool $unique,
        private ?string $comment,
        private ?string $algorithm,
        private ?string $filterExpression,
    ) {
    }

    #[\Override]
    public function getName(): string
    {
        return $this->name;
    }
    #[\Override]
    public function getType(): IndexType
    {
        return $this->type;
    }
    /** @return list<IndexColumnDefinitionInterface> */
    #[\Override]
    public function getColumns(): array
    {
        return $this->columns;
    }
    #[\Override]
    public function isUnique(): bool
    {
        return $this->unique;
    }
    #[\Override]
    public function getComment(): ?string
    {
        return $this->comment;
    }
    #[\Override]
    public function getAlgorithm(): ?string
    {
        return $this->algorithm;
    }
    #[\Override]
    public function getFilterExpression(): ?string
    {
        return $this->filterExpression;
    }
}
