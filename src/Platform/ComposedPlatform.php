<?php
declare(strict_types=1);
namespace SQLCraft\Platform;
use SQLCraft\Capabilities\CapabilitySet;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\Contracts\Platform\IntrospectionDialectInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Contracts\Platform\QueryDialectInterface;
use SQLCraft\Contracts\Platform\QuotingInterface;
use SQLCraft\Contracts\Platform\TypeMapperInterface;
use SQLCraft\ValueObjects\ServerVersion;
final class ComposedPlatform extends MySQLPlatform
{
    public function __construct(private string $name, private PlatformRoles $roles, private \Closure $serverVersion, private \Closure $capabilities, private ?string $flavor = null, private ?string $defaultCharset = null, private ?string $defaultCollation = null, private bool $supportsSchemas = false) {}
    public function getName(): string { return $this->name; }
    public function getFlavor(): ?string { return $this->flavor; }
    public function getServerVersion(ConnectionInterface $connection): ServerVersion { return ($this->serverVersion)($connection); }
    public function getCapabilitySet(ServerVersion $version): CapabilitySet { return ($this->capabilities)($version); }
    public function getDefaultCharset(): ?string { return $this->defaultCharset; }
    public function getDefaultCollation(): ?string { return $this->defaultCollation; }
    public function supportsSchemas(): bool { return $this->supportsSchemas; }
    public function ddl(): DdlDialectInterface { return $this->roles->ddl; }
    public function introspection(): IntrospectionDialectInterface { return $this->roles->introspection; }
    public function queryDialect(): QueryDialectInterface { return $this->roles->queryDialect; }
    public function quoting(): QuotingInterface { return $this->roles->quoting; }
    public function types(): TypeMapperInterface { return $this->roles->types; }
    public function roles(): PlatformRoles { return $this->roles; }
}
