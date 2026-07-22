<?php

declare(strict_types=1);

namespace SQLCraft\DTO;

use SQLCraft\ValueObjects\IndexType;

final readonly class IndexMeta
{
    /**
     * @param  list<IndexColumnMeta>  $columns
     */
    public function __construct(
        public string $name,
        public IndexType $type,
        public array $columns,
        public bool $unique,
        public ?string $comment,
        public ?string $algorithm,
        public ?string $filterExpression,
    ) {}
}
