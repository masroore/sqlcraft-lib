<?php

declare(strict_types=1);

namespace SQLCraft\Connection\Result;

use Closure;
use Iterator;
use SQLCraft\Contracts\Connection\ResultColumn;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\Exceptions\StreamingResultException;

/** @internal */
final class StreamingResult implements ResultInterface
{
    /** @var Closure(): Iterator<int, array<string, int|float|string|bool|null>> */
    private Closure $factory;

    /** @var list<ResultColumn> */
    private array $columns;

    /** @var Iterator<int, array<string, int|float|string|bool|null>>|null */
    private ?Iterator $iterator = null;

    private bool $started = false;

    /**
     * @param Closure(): Iterator<int, array<string, int|float|string|bool|null>> $factory
     * @param list<ResultColumn> $columns
     */
    public function __construct(Closure $factory, array $columns = [])
    {
        $this->factory = $factory;
        $this->columns = $columns;
    }

    /** @return array<string, int|float|string|bool|null>|null */
    #[\Override]
    public function fetchAssoc(): ?array
    {
        $iterator = $this->getIteratorSource();
        if (!$iterator->valid()) {
            return null;
        }

        $row = $iterator->current();
        $iterator->next();

        return $row;
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
        $rows = [];
        while (($row = $this->fetchAssoc()) !== null) {
            $rows[] = $row;
        }

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
        throw new StreamingResultException('Streaming results cannot seek.');
    }

    #[\Override]
    public function isStreaming(): bool
    {
        return true;
    }

    #[\Override]
    public function count(): int
    {
        throw new StreamingResultException('Streaming results cannot be counted.');
    }

    #[\Override]
    public function getIterator(): \Traversable
    {
        while (($row = $this->fetchAssoc()) !== null) {
            yield $row;
        }
    }

    /** @return Iterator<int, array<string, int|float|string|bool|null>> */
    private function getIteratorSource(): Iterator
    {
        if (!$this->iterator instanceof \Iterator) {
            /** @var Iterator<int, array<string, int|float|string|bool|null>> $iterator */
            $iterator = ($this->factory)();
            $this->iterator = $iterator;
        }

        if (!$this->started) {
            $this->iterator->rewind();
            $this->started = true;
        }

        return $this->iterator;
    }
}
