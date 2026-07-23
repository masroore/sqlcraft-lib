<?php

declare(strict_types=1);

namespace SQLCraft\DDL\Definition;

use SQLCraft\Contracts\DDL\CheckConstraintDefinitionInterface;

final readonly class CheckConstraintDefinition implements CheckConstraintDefinitionInterface
{
    public function __construct(
        private string $name,
        private string $expression,
        private bool $enforced,
    ) {
    }

    #[\Override]
    public function getName(): string
    {
        return $this->name;
    }

    #[\Override]
    public function getExpression(): string
    {
        return $this->expression;
    }

    #[\Override]
    public function isEnforced(): bool
    {
        return $this->enforced;
    }
}
