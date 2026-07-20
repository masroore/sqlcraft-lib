<?php

declare(strict_types=1);

namespace SQLCraft\Platform;

use SQLCraft\Capabilities\Capability;
use SQLCraft\Capabilities\CapabilitySet;
use SQLCraft\Capabilities\ExtendedCapability;
use SQLCraft\Capabilities\PlatformCapabilityResolver;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\ServerVersion;

abstract class AbstractPlatform implements PlatformInterface
{
    #[\Override]
    public function quoteIdentifier(Identifier $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier->name) . '"';
    }

    #[\Override]
    public function applySingleRowLimit(string $sql, string $whereClause): string
    {
        return $sql . ' LIMIT 1';
    }

    #[\Override]
    public function getCapabilitySet(ServerVersion $version): CapabilitySet
    {
        return (new PlatformCapabilityResolver($this->buildCapabilityMatrix()))
            ->resolve($this->getName(), $version);
    }

    /**
     * @return array{always: list<Capability|ExtendedCapability>, versioned: list<array{0: Capability|ExtendedCapability, 1: array{0: int, 1: int, 2: int}}>}
     */
    abstract protected function buildCapabilityMatrix(): array;
}
