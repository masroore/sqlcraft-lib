<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\DDL;

use SQLCraft\ValueObjects\IndexType;

interface IndexDefinitionInterface
{
    public function getName(): string;

    public function getType(): IndexType;

    /** @return list<IndexColumnDefinitionInterface> */
    public function getColumns(): array;

    public function isUnique(): bool;

    public function getComment(): ?string;

    public function getAlgorithm(): ?string;

    public function getFilterExpression(): ?string;
}
