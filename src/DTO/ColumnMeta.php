<?php

declare(strict_types=1);

namespace SQLCraft\DTO;

use SQLCraft\ValueObjects\Collation;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\DefaultValue;

final readonly class ColumnMeta
{
    /**
     * @param  list<int>  $privileges
     */
    public function __construct(
        public string $name,
        public DataType $dataType,
        public bool $nullable,
        public bool $autoIncrement,
        public bool $primary,
        public bool $generated,
        public DefaultValue $default,
        public ?Collation $collation,
        public ?string $comment,
        public ?string $onUpdate,
        public array $privileges,
        public ?string $origName,
        public ?string $defaultConstraintName,
    ) {}
}
