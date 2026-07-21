<?php

declare(strict_types=1);

namespace SQLCraft\Import;

use InvalidArgumentException;
use SQLCraft\Contracts\Import\ImportSourceInterface;

final readonly class StreamImportSource implements ImportSourceInterface
{
    /** @param mixed $stream */
    public function __construct(private mixed $stream)
    {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('StreamImportSource requires an open stream resource.');
        }
    }

    #[\Override]
    public function openStream(): mixed
    {
        return $this->stream;
    }

    #[\Override]
    public function getEstimatedSize(): ?int
    {
        if (!is_resource($this->stream)) {
            return null;
        }
        $stat = fstat($this->stream);
        return is_array($stat) ? $stat['size'] : null;
    }
}
