<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Capabilities;

use SQLCraft\Capabilities\CapabilitySet;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\ValueObjects\ServerVersion;

interface CapabilityResolverInterface
{
    /**
     * Resolve capabilities for a platform at a given server version.
     * May introspect the live connection for fine-grained version detection.
     */
    public function resolve(
        string $platformName,
        ServerVersion $version,
        ?ConnectionInterface $connection = null,
    ): CapabilitySet;
}
