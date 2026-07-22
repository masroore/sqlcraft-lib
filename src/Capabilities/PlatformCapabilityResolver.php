<?php

declare(strict_types=1);

namespace SQLCraft\Capabilities;

use SQLCraft\Contracts\Capabilities\CapabilityResolverInterface;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Events\SchemaEventDispatcherInterface;
use SQLCraft\ValueObjects\ServerVersion;

final class PlatformCapabilityResolver implements CapabilityResolverInterface
{
    /**
     * @param array{
     *     always?: list<Capability|ExtendedCapability>,
     *     versioned?: list<array{0: Capability|ExtendedCapability, 1: array{0: int, 1: int, 2: int}}>
     * } $matrix
     */
    public function __construct(
        private readonly array $matrix,
        private readonly ?SchemaEventDispatcherInterface $events = null,
    ) {}

    #[\Override]
    public function resolve(
        string $platformName,
        ServerVersion $version,
        ?ConnectionInterface $connection = null,
    ): CapabilitySet {
        $capabilities = $this->matrix['always'] ?? [];

        foreach ($this->matrix['versioned'] ?? [] as [$capability, $minimum]) {
            if ($version->isAtLeast($minimum[0], $minimum[1], $minimum[2])) {
                $capabilities[] = $capability;
            }
        }

        return new CapabilitySet(array_values(array_unique($capabilities, SORT_REGULAR)), $this->events, $platformName, (string) $version);
    }
}
