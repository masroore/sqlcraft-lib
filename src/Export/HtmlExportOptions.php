<?php

declare(strict_types=1);

namespace SQLCraft\Export;

final readonly class HtmlExportOptions
{
    public function __construct(
        public ?string $templatePath = null,
        public ?string $templateString = null,
        public string $title = 'Database Export',
        public bool $useTwig = false,
    ) {
    }
}
