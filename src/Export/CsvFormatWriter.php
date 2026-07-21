<?php

declare(strict_types=1);

namespace SQLCraft\Export;

final class CsvFormatWriter extends AbstractDelimitedFormatWriter
{
    #[\Override]
    public function getFormatName(): string
    {
        return 'csv';
    }

    #[\Override]
    protected function defaultSeparator(): string
    {
        return ',';
    }
}
