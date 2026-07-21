<?php

declare(strict_types=1);

namespace SQLCraft\Export;

use SQLCraft\Contracts\Export\SinkInterface;
use SQLCraft\Exceptions\ExtensionMissingException;

final class Bzip2Sink implements SinkInterface
{
    /** @var resource */
    private mixed $buffer;
    private bool $closed = false;

    public function __construct(private SinkInterface $inner, int $blockSize = 9)
    {
        if (!extension_loaded('bz2')) {
            throw new ExtensionMissingException('bz2');
        }
        $buffer = fopen('php://temp', 'w+b');
        if ($buffer === false) {
            throw new \RuntimeException('Unable to allocate bzip2 buffer.');
        }
        $this->buffer = $buffer;
        if ($blockSize < 1 || $blockSize > 9) {
            throw new \InvalidArgumentException('Bzip2 block size must be between 1 and 9.');
        }
        $this->blockSize = $blockSize;
    }

    private int $blockSize;

    #[\Override]
    public function write(string $bytes): void
    {
        $this->assertOpen();
        if (fwrite($this->buffer, $bytes) !== strlen($bytes)) {
            throw new \RuntimeException('Unable to buffer bzip2 export output.');
        }
    }

    #[\Override]
    public function flush(): void
    {
        $this->assertOpen();
        $this->inner->flush();
    }

    #[\Override]
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        rewind($this->buffer);
        $contents = stream_get_contents($this->buffer);
        if ($contents === false) {
            throw new \RuntimeException('Unable to read bzip2 export buffer.');
        }
        $compressed = bzcompress($contents, $this->blockSize);
        if (!is_string($compressed)) {
            throw new \RuntimeException('Unable to compress export output with bzip2.');
        }
        $this->inner->write($compressed);
        $this->inner->close();
        /** @psalm-suppress InvalidPropertyAssignmentValue */
        fclose($this->buffer);
        $this->closed = true;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new \RuntimeException('Export sink is closed.');
        }
    }
}
