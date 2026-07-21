<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\DDL;

interface IndexColumnDefinitionInterface
{
    public function getColumnName(): string;

    public function isDescending(): bool;

    public function getLength(): ?int;

    public function getExpression(): ?string;
}
