<?php

declare(strict_types=1);

namespace SQLCraft\Import;

use InvalidArgumentException;
use SQLCraft\Contracts\Import\ImportSourceInterface;
use SQLCraft\Exceptions\ExtensionMissingException;

final readonly class FileImportSource implements ImportSourceInterface
{
    public function __construct(private string $path)
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException(sprintf('Import file is not readable: %s.', $path));
        }
    }

    #[\Override]
    public function openStream(): mixed
    {
        $stream = fopen($this->path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException(sprintf('Unable to open import file: %s.', $this->path));
        }

        $magic = fread($stream, 2);
        rewind($stream);
        $filter = null;
        if ($magic === "\x1f\x8b") {
            $filter = 'zlib.inflate';
            $this->requireExtension('zlib');
        } elseif (str_ends_with(strtolower($this->path), '.bz2')) {
            $filter = 'bzip2.decompress';
            $this->requireExtension('bz2');
        }
        $parameters = $filter === 'zlib.inflate' ? ['window' => 31] : null;
        if ($filter !== null && stream_filter_append($stream, $filter, STREAM_FILTER_READ, $parameters) === false) {
            fclose($stream);
            throw new \RuntimeException(sprintf('Unable to attach %s decompression filter.', $filter));
        }

        return $stream;
    }

    #[\Override]
    public function getEstimatedSize(): ?int
    {
        $size = filesize($this->path);

        return $size === false ? null : $size;
    }

    private function requireExtension(string $extension): void
    {
        if (! extension_loaded($extension)) {
            throw new ExtensionMissingException($extension);
        }
    }
}
