<?php

declare(strict_types=1);

namespace SQLCraft\Import;

use SQLCraft\Contracts\Import\ImportSourceInterface;

final readonly class StringImportSource implements ImportSourceInterface
{
    public function __construct(private string $contents)
    {
    }

    #[\Override]
    public function openStream(): mixed
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('Unable to allocate import stream.');
        }
        fwrite($stream, $this->contents);
        rewind($stream);
        return $stream;
    }

    #[\Override]
    public function getEstimatedSize(): int
    {
        return strlen($this->contents);
    }
}
