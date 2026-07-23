<?php

declare(strict_types=1);

namespace SQLCraft\Export;

use SQLCraft\Contracts\Export\SinkInterface;

final class StringBufferSink implements SinkInterface
{
    private string $buffer = '';

    #[\Override]
    public function write(string $bytes): void
    {
        $this->buffer .= $bytes;
    }

    #[\Override]
    public function flush(): void
    {
    }

    #[\Override]
    public function close(): void
    {
    }

    public function contents(): string
    {
        return $this->buffer;
    }
}
