<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\DDL;

use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\RoutineDirection;

interface RoutineParameterDefinitionInterface
{
    public function getName(): string;
    public function getDataType(): DataType;
    public function getDirection(): RoutineDirection;
}
