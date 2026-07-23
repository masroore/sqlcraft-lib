<?php

declare(strict_types=1);

namespace SQLCraft\DTO;

final readonly class SchemaMeta
{
    public function __construct(
        public string $name,
        public ?string $catalog,
        public ?string $owner,
    ) {
    }
}
