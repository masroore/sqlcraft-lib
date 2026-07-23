<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Platform;

use SQLCraft\Capabilities\CapabilitySet;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\ValueObjects\ServerVersion;

interface PlatformInterface
{
    public function getName(): string;

    public function getFlavor(): ?string;

    public function getServerVersion(ConnectionInterface $connection): ServerVersion;

    public function getCapabilitySet(ServerVersion $version): CapabilitySet;

    public function getDefaultCharset(): ?string;

    public function getDefaultCollation(): ?string;

    public function supportsSchemas(): bool;

    public function ddl(): DdlDialectInterface;

    public function introspection(): IntrospectionDialectInterface;

    public function queryDialect(): QueryDialectInterface;

    public function quoting(): QuotingInterface;

    public function types(): TypeMapperInterface;
}
