<?php

declare(strict_types=1);

namespace SQLCraft\DTO;

use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\RoutineDirection;

final readonly class RoutineParameter
{
    public function __construct(
        public string $name,
        public DataType $dataType,
        public RoutineDirection $direction,
    ) {}
}
