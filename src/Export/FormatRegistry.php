<?php

declare(strict_types=1);

namespace SQLCraft\Export;

use SQLCraft\Contracts\Export\FormatWriterInterface;

final class FormatRegistry
{
    /** @var array<string, FormatWriterInterface> */
    private array $writers = [];

    /** @param iterable<FormatWriterInterface> $writers */
    public function __construct(iterable $writers = [])
    {
        foreach ($writers as $writer) {
            $this->registerWriter($writer);
        }
    }

    public function registerWriter(FormatWriterInterface $writer): void
    {
        $this->writers[$writer->getFormatName()] = $writer;
    }

    public function getWriter(string $format): FormatWriterInterface
    {
        return $this->writers[$format] ?? throw new \InvalidArgumentException(sprintf('Unsupported export format: %s.', $format));
    }

    /** @return list<string> */
    public function getSupportedWriteFormats(): array
    {
        return array_keys($this->writers);
    }
}
