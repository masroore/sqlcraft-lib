<?php

declare(strict_types=1);

namespace SQLCraft\Export;

final readonly class XlsxExportOptions
{
    public function __construct(
        public ?string $sheetPrefix = null,
        public bool $freezeHeaderRow = true,
    ) {
    }
}
