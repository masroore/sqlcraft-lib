<?php

declare(strict_types=1);

namespace SQLCraft\DTO;

use SQLCraft\ValueObjects\DataType;

final readonly class RoutineMeta
{
    /**
     * @param list<RoutineParameter> $params
     */
    public function __construct(
        public string $name,
        public string $type,
        public array $params,
        public ?DataType $returnType,
        public string $body,
        public ?string $language,
        public ?string $comment,
        public string $definer,
        public bool $deterministic,
        public string $sqlDataAccess,
    ) {
    }
}
