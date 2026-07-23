<?php

declare(strict_types=1);

namespace SQLCraft\Platform;

use Closure;
use SQLCraft\Capabilities\CapabilitySet;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\Contracts\Platform\IntrospectionDialectInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Contracts\Platform\QueryDialectInterface;
use SQLCraft\Contracts\Platform\QuotingInterface;
use SQLCraft\Contracts\Platform\TypeMapperInterface;
use SQLCraft\ValueObjects\ServerVersion;

final class ComposedPlatform implements PlatformInterface
{
    private string $name;

    private PlatformRoles $roles;

    /** @var Closure(ConnectionInterface): ServerVersion */
    private Closure $serverVersion;

    /** @var Closure(ServerVersion): CapabilitySet */
    private Closure $capabilities;

    private ?string $flavor;

    private ?string $defaultCharset;

    private ?string $defaultCollation;

    private bool $supportsSchemas;

    /**
     * @param  Closure(ConnectionInterface): ServerVersion  $serverVersion
     * @param  Closure(ServerVersion): CapabilitySet  $capabilities
     */
    public function __construct(
        string $name,
        PlatformRoles $roles,
        Closure $serverVersion,
        Closure $capabilities,
        ?string $flavor = null,
        ?string $defaultCharset = null,
        ?string $defaultCollation = null,
        bool $supportsSchemas = false,
    ) {
        $this->name = $name;
        $this->roles = $roles;
        $this->serverVersion = $serverVersion;
        $this->capabilities = $capabilities;
        $this->flavor = $flavor;
        $this->defaultCharset = $defaultCharset;
        $this->defaultCollation = $defaultCollation;
        $this->supportsSchemas = $supportsSchemas;
    }

    #[\Override]
    public function getName(): string
    {
        return $this->name;
    }

    #[\Override]
    public function getFlavor(): ?string
    {
        return $this->flavor;
    }

    #[\Override]
    public function getServerVersion(ConnectionInterface $connection): ServerVersion
    {
        return ($this->serverVersion)($connection);
    }

    #[\Override]
    public function getCapabilitySet(ServerVersion $version): CapabilitySet
    {
        return ($this->capabilities)($version);
    }

    #[\Override]
    public function getDefaultCharset(): ?string
    {
        return $this->defaultCharset;
    }

    #[\Override]
    public function getDefaultCollation(): ?string
    {
        return $this->defaultCollation;
    }

    #[\Override]
    public function supportsSchemas(): bool
    {
        return $this->supportsSchemas;
    }

    #[\Override]
    public function ddl(): DdlDialectInterface
    {
        return $this->roles->ddl;
    }

    #[\Override]
    public function introspection(): IntrospectionDialectInterface
    {
        return $this->roles->introspection;
    }

    #[\Override]
    public function queryDialect(): QueryDialectInterface
    {
        return $this->roles->queryDialect;
    }

    #[\Override]
    public function quoting(): QuotingInterface
    {
        return $this->roles->quoting;
    }

    #[\Override]
    public function types(): TypeMapperInterface
    {
        return $this->roles->types;
    }

    public function roles(): PlatformRoles
    {
        return $this->roles;
    }
}
