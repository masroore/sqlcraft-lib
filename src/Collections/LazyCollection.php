<?php

declare(strict_types=1);

namespace SQLCraft\Collections;

use Countable;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<int|string, object> */
final class LazyCollection implements IteratorAggregate, Countable
{
    /** @var \Closure(): iterable<int|string, object> */
    private readonly \Closure $producer;

    /** @var array<int|string, object>|null */
    private ?array $items = null;

    /** @param \Closure(): iterable<int|string, object> $producer */
    public function __construct(\Closure $producer)
    {
        $this->producer = $producer;
    }

    #[\Override]
    public function getIterator(): Traversable
    {
        yield from $this->materialize();
    }

    #[\Override]
    public function count(): int
    {
        return count($this->materialize());
    }

    /** @return array<int|string, object> */
    private function materialize(): array
    {
        if ($this->items === null) {
            $this->items = iterator_to_array(($this->producer)(), true);
        }

        return $this->items;
    }
}
