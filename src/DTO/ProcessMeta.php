<?php

declare(strict_types=1);

namespace SQLCraft\DTO;

final readonly class ProcessMeta
{
    public function __construct(
        public int $id,
        public string $user,
        public ?string $host,
        public ?string $database,
        public string $command,
        public int $time,
        public ?string $state,
        public ?string $query,
    ) {}
}
