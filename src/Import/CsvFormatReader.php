<?php

declare(strict_types=1);

namespace SQLCraft\Import;

use InvalidArgumentException;
use SQLCraft\Contracts\Import\FormatReadOptions;
use SQLCraft\Contracts\Import\FormatReaderInterface;

final readonly class CsvFormatReader implements FormatReaderInterface
{
    #[\Override]
    public function getFormatName(): string
    {
        return 'csv';
    }

    /** @return \Generator<int, array<string, mixed>> */
    #[\Override]
    public function readRows(mixed $stream, FormatReadOptions $options): \Generator
    {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('CSV reader requires a stream resource.');
        }
        if (!$options->hasHeader) {
            throw new InvalidArgumentException('CSV reader requires a header row.');
        }
        $header = fgetcsv($stream, 0, $options->separator, '"', '');
        if (!is_array($header)) {
            return;
        }
        /** @var list<string> $normalizedHeader */
        $normalizedHeader = [];
        foreach ($header as $value) {
            $normalizedHeader[] = is_string($value) ? $value : '';
        }
        while (($values = fgetcsv($stream, 0, $options->separator, '"', '')) !== false) {
            /** @var list<string|null> $values */
            yield $this->row($normalizedHeader, $values, $options->nullRepresentation);
        }
    }

    /**
     * @param list<string> $header
     * @param list<string|null> $values
     * @return array<string, mixed>
     */
    private function row(array $header, array $values, string $nullRepresentation): array
    {
        $row = [];
        foreach ($header as $index => $name) {
            $value = $values[$index] ?? null;
            $row[$name] = $value === $nullRepresentation ? null : $value;
        }
        return $row;
    }
}
