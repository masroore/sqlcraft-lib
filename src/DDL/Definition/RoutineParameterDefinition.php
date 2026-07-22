<?php

declare(strict_types=1);

namespace SQLCraft\DDL\Definition;

use SQLCraft\Contracts\DDL\RoutineParameterDefinitionInterface;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\RoutineDirection;

final readonly class RoutineParameterDefinition implements RoutineParameterDefinitionInterface
{
    public function __construct(
        private string $name,
        private DataType $dataType,
        private RoutineDirection $direction,
    ) {}

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
    public function getDirection(): RoutineDirection
    {
        return $this->direction;
    }
}
