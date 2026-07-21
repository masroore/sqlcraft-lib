<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Import;

interface FormatReaderInterface
{
    public function getFormatName(): string;

    /** @return \Generator<int, array<string, mixed>> */
    public function readRows(mixed $stream, FormatReadOptions $options): \Generator;
}
