<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\DDL;

interface CheckConstraintDefinitionInterface
{
    public function getName(): string;

    public function getExpression(): string;

    public function isEnforced(): bool;
}
