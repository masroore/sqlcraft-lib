<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Connection;

/**
 * @extends \IteratorAggregate<int, array<string, mixed>>
 */
interface ResultInterface extends \IteratorAggregate, \Countable
{
    /** @return array<string, mixed>|null */
    public function fetchAssoc(): ?array;

    /** @return list<mixed>|null */
    public function fetchRow(): ?array;

    /** @return list<array<string, mixed>> */
    public function fetchAll(): array;

    /** @return list<mixed> */
    public function fetchColumn(int|string $column = 0): array;

    /** @return list<ResultColumn> */
    public function getColumns(): array;

    public function seek(int $offset): void;

    public function isStreaming(): bool;

    #[\Override]
    public function count(): int;

    #[\Override]
    public function getIterator(): \Traversable;
}
