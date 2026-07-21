<?php

declare(strict_types=1);

namespace SQLCraft\Export;

use SQLCraft\Contracts\Export\SinkInterface;
use SQLCraft\Exceptions\ExtensionMissingException;

final class GzipSink implements SinkInterface
{
    private \DeflateContext $context;
    private bool $closed = false;

    public function __construct(private SinkInterface $inner, int $level = -1)
    {
        if (!extension_loaded('zlib')) {
            throw new ExtensionMissingException('zlib');
        }
        $context = deflate_init(ZLIB_ENCODING_GZIP, ['level' => $level]);
        if (!$context instanceof \DeflateContext) {
            throw new \RuntimeException('Unable to initialize gzip compression.');
        }
        $this->context = $context;
    }

    #[\Override]
    public function write(string $bytes): void
    {
        $this->assertOpen();
        $compressed = deflate_add($this->context, $bytes, ZLIB_SYNC_FLUSH);
        if ($compressed === false) {
            throw new \RuntimeException('Unable to compress export output.');
        }
        if ($compressed !== '') {
            $this->inner->write($compressed);
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
        $compressed = deflate_add($this->context, '', ZLIB_FINISH);
        if ($compressed === false) {
            throw new \RuntimeException('Unable to finish gzip compression.');
        }
        if ($compressed !== '') {
            $this->inner->write($compressed);
        }
        $this->inner->close();
        $this->closed = true;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new \RuntimeException('Export sink is closed.');
        }
    }
}
