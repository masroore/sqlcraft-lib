<?php

declare(strict_types=1);

namespace SQLCraft\Capabilities;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;
use SQLCraft\Capabilities\CapabilityNotSupportedException;

/**
 * @implements IteratorAggregate<int, Capability|ExtendedCapability>
 */
final readonly class CapabilitySet implements IteratorAggregate, Countable
{
    /**
     * @param list<Capability|ExtendedCapability> $capabilities
     */
    public function __construct(private array $capabilities)
    {
    }

    public function has(Capability|ExtendedCapability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    public function require(Capability|ExtendedCapability $capability): void
    {
        if (!$this->has($capability)) {
            throw CapabilityNotSupportedException::for($capability);
        }
    }

    public function intersect(self $other): self
    {
        return new self(array_values(array_filter(
            $this->capabilities,
            $other->has(...),
        )));
    }

    /** @return list<Capability|ExtendedCapability> */
    public function toArray(): array
    {
        return $this->capabilities;
    }

    /** @return Traversable<int, Capability|ExtendedCapability> */
    #[\Override]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->capabilities);
    }

    #[\Override]
    public function count(): int
    {
        return count($this->capabilities);
    }
}
