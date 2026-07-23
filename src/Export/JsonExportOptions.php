<?php

declare(strict_types=1);

namespace SQLCraft\Export;

final readonly class JsonExportOptions
{
    public function __construct(
        public bool $pretty = true,
    ) {
    }
}
