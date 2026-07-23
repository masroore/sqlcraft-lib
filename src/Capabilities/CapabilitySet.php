<?php

declare(strict_types=1);

namespace SQLCraft\Capabilities;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use SQLCraft\Contracts\Events\SchemaEventDispatcherInterface;
use Traversable;

/**
 * @implements IteratorAggregate<int, Capability|ExtendedCapability>
 */
final readonly class CapabilitySet implements Countable, IteratorAggregate
{
    /**
     * @param  list<Capability|ExtendedCapability>  $capabilities
     */
    public function __construct(
        private array $capabilities,
        private ?SchemaEventDispatcherInterface $events = null,
        private string $platform = '',
        private string $version = '',
    ) {
    }

    public function has(Capability|ExtendedCapability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    public function require(Capability|ExtendedCapability $capability): void
    {
        if (! $this->has($capability)) {
            $capabilityName = $capability instanceof Capability ? $capability->value : $capability->name;
            $this->events?->capabilityNotSupported($capabilityName, $this->platform, $this->version);
            throw CapabilityNotSupportedException::for($capability, $this->platform, $this->version);
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
