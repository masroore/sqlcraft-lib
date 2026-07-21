<?php

declare(strict_types=1);

namespace SQLCraft\Export;

final class CsvSemicolonFormatWriter extends AbstractDelimitedFormatWriter
{
    #[\Override]
    public function getFormatName(): string
    {
        return 'csv-semicolon';
    }

    #[\Override]
    protected function defaultSeparator(): string
    {
        return ';';
    }
}
