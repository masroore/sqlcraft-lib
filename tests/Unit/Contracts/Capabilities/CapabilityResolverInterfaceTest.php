<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Contracts\Capabilities;

use PHPUnit\Framework\TestCase;
use SQLCraft\Capabilities\Capability;
use SQLCraft\Capabilities\CapabilitySet;
use SQLCraft\Contracts\Capabilities\CapabilityResolverInterface;
use SQLCraft\ValueObjects\ServerVersion;

final class CapabilityResolverInterfaceTest extends TestCase
{
    public function testImplementationsResolveCapabilitiesWithoutAConnection(): void
    {
        $capabilities = new CapabilitySet([Capability::Table]);
        $resolver = new class ($capabilities) implements CapabilityResolverInterface {
            public function __construct(private readonly CapabilitySet $capabilities)
            {
            }

            #[\Override]
            public function resolve(
                string $platformName,
                ServerVersion $version,
                ?\SQLCraft\Contracts\Connection\ConnectionInterface $connection = null,
            ): CapabilitySet {
                return $this->capabilities;
            }
        };

        self::assertSame(
            $capabilities,
            $resolver->resolve('sqlite', new ServerVersion('3.45.0')),
        );
    }
}
