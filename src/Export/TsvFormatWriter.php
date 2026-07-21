<?php

declare(strict_types=1);

namespace SQLCraft\Export;

final class TsvFormatWriter extends AbstractDelimitedFormatWriter
{
    #[\Override]
    public function getFormatName(): string
    {
        return 'tsv';
    }

    #[\Override]
    protected function defaultSeparator(): string
    {
        return "\t";
    }
}
