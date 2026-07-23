<?php

declare(strict_types=1);

namespace SQLCraft\Collections;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use LogicException;
use OutOfBoundsException;
use Traversable;

/**
 * @template T of object
 *
 * @psalm-consistent-constructor
 *
 * @implements IteratorAggregate<int|string, T>
 * @implements ArrayAccess<int|string, T>
 */
abstract class AbstractImmutableCollection implements ArrayAccess, Countable, IteratorAggregate
{
    /**
     * @param  array<int|string, T>  $items
     */
    final public function __construct(protected readonly array $items)
    {
    }

    /**
     * @return T
     */
    public function get(int|string $key): object
    {
        if (! array_key_exists($key, $this->items)) {
            throw new OutOfBoundsException(sprintf('Collection key not found: %s', (string) $key));
        }

        return $this->items[$key];
    }

    /**
     * @param  \Closure(T): bool  $predicate
     *
     * @psalm-return static
     */
    /**
     * @param  array<int|string, T>  $items
     *
     * @psalm-return static
     */
    abstract protected function create(array $items): static;

    public function filter(\Closure $predicate): static
    {
        $items = [];

        foreach ($this->items as $key => $item) {
            if ($predicate($item)) {
                $items[$key] = $item;
            }
        }

        return $this->create($items);
    }

    /**
     * @template U
     *
     * @param  \Closure(T): U  $mapper
     * @return list<U>
     */
    public function map(\Closure $mapper): array
    {
        $items = [];

        foreach ($this->items as $item) {
            $items[] = $mapper($item);
        }

        return $items;
    }

    #[\Override]
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return Traversable<int|string, T>
     */
    #[\Override]
    public function getIterator(): Traversable
    {
        yield from $this->items;
    }

    /** @param int|string $offset */
    #[\Override]
    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->items);
    }

    /**
     * @param  int|string  $offset
     * @return T
     */
    #[\Override]
    public function offsetGet(mixed $offset): object
    {
        return $this->get($offset);
    }

    /**
     * ArrayAccess requires mixed parameters; mutation is rejected for immutability.
     */
    #[\Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Immutable collections cannot be modified.');
    }

    #[\Override]
    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Immutable collections cannot be modified.');
    }
}
