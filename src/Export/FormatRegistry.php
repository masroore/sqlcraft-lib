<?php

declare(strict_types=1);

namespace SQLCraft\Export;

use SQLCraft\Contracts\Export\FormatWriterInterface;
use SQLCraft\Contracts\Import\FormatReaderInterface;

final class FormatRegistry
{
    /** @var array<string, FormatWriterInterface> */
    private array $writers = [];
    /** @var array<string, FormatReaderInterface> */
    private array $readers = [];

    /**
     * @param iterable<FormatWriterInterface> $writers
     * @param iterable<FormatReaderInterface> $readers
     */
    public function __construct(iterable $writers = [], iterable $readers = [])
    {
        foreach ($writers as $writer) {
            $this->registerWriter($writer);
        }
        foreach ($readers as $reader) {
            $this->registerReader($reader);
        }
    }

    public function registerWriter(FormatWriterInterface $writer): void
    {
        $this->writers[$writer->getFormatName()] = $writer;
    }

    public function registerReader(FormatReaderInterface $reader): void
    {
        $this->readers[$reader->getFormatName()] = $reader;
    }

    public function getWriter(string $format): FormatWriterInterface
    {
        return $this->writers[$format] ?? throw new \InvalidArgumentException(sprintf('Unsupported export format: %s.', $format));
    }

    public function getReader(string $format): FormatReaderInterface
    {
        return $this->readers[$format] ?? throw new \InvalidArgumentException(sprintf('Unsupported import format: %s.', $format));
    }

    /** @return list<string> */
    public function getSupportedWriteFormats(): array
    {
        return array_keys($this->writers);
    }

    /** @return list<string> */
    public function getSupportedReadFormats(): array
    {
        return array_keys($this->readers);
    }
}
