<?php

declare(strict_types=1);

namespace SQLCraft\Export;

final readonly class XmlExportOptions
{
    public function __construct(
        public string $rootElement = 'export',
        public string $rowElement = 'row',
    ) {
    }
}
