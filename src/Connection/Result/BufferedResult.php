<?php

declare(strict_types=1);

namespace SQLCraft\Connection\Result;

use OutOfBoundsException;
use SQLCraft\Contracts\Connection\ResultColumn;
use SQLCraft\Contracts\Connection\ResultInterface;

/** @internal */
final class BufferedResult implements ResultInterface
{
    /** @var list<array<string, int|float|string|bool|null>> */
    private array $rows;

    /** @var list<ResultColumn> */
    private array $columns;

    private int $position = 0;

    /**
     * @param list<array<string, int|float|string|bool|null>> $rows
     * @param list<ResultColumn> $columns
     */
    public function __construct(array $rows, array $columns = [])
    {
        $this->rows = $rows;
        $this->columns = $columns;
    }

    /** @return array<string, int|float|string|bool|null>|null */
    #[\Override]
    public function fetchAssoc(): ?array
    {
        if (!isset($this->rows[$this->position])) {
            return null;
        }

        return $this->rows[$this->position++];
    }

    #[\Override]
    public function fetchRow(): ?array
    {
        $row = $this->fetchAssoc();

        return $row === null ? null : array_values($row);
    }

    #[\Override]
    public function fetchAll(): array
    {
        $rows = array_slice($this->rows, $this->position);
        $this->position = count($this->rows);

        return $rows;
    }

    #[\Override]
    public function fetchColumn(int|string $column = 0): array
    {
        /** @var list<int|float|string|bool|null> $values */
        $values = [];
        while (($row = $this->fetchAssoc()) !== null) {
            $values[] = $this->valueAt($row, $column);
        }

        return $values;
    }

    /** @param array<string, int|float|string|bool|null> $row */
    private function valueAt(array $row, int|string $column): int|float|string|bool|null
    {
        if (is_int($column)) {
            $index = 0;
            foreach ($row as $value) {
                if ($index++ === $column) {
                    return $value;
                }
            }

            return null;
        }

        return $row[$column] ?? null;
    }

    #[\Override]
    public function getColumns(): array
    {
        return $this->columns;
    }

    #[\Override]
    public function seek(int $offset): void
    {
        if ($offset < 0 || $offset > count($this->rows)) {
            throw new OutOfBoundsException('Buffered result offset is outside the result set.');
        }

        $this->position = $offset;
    }

    #[\Override]
    public function isStreaming(): bool
    {
        return false;
    }

    #[\Override]
    public function count(): int
    {
        return count($this->rows);
    }

    #[\Override]
    public function getIterator(): \Traversable
    {
        while (($row = $this->fetchAssoc()) !== null) {
            yield $row;
        }
    }
}
