<?php

declare(strict_types=1);

namespace SQLCraft\DTO;

final readonly class TableStatus
{
    public function __construct(
        public string $name,
        public bool $isView = false,
        public ?string $engine = null,
        public ?string $comment = null,
        public ?int $oid = null,
        public ?int $rows = null,
        public ?string $collation = null,
        public ?int $autoIncrement = null,
        public ?int $dataLength = null,
        public ?int $indexLength = null,
        public ?int $dataFree = null,
        public ?string $createOptions = null,
        public bool $partitioned = false,
        public ?string $schema = null,
    ) {
    }
}
