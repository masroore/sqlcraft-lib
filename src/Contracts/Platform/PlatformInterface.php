<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Platform;

use SQLCraft\Capabilities\CapabilitySet;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\ValueObjects\ServerVersion;

interface PlatformInterface extends DdlDialectInterface, IntrospectionDialectInterface, PaginationInterface, QuotingInterface, TypeMapperInterface
{
    public function getName(): string;

    public function getFlavor(): ?string;

    public function getServerVersion(ConnectionInterface $connection): ServerVersion;

    public function getCapabilitySet(ServerVersion $version): CapabilitySet;

    public function getDefaultCharset(): ?string;

    public function getDefaultCollation(): ?string;

    public function supportsSchemas(): bool;

    /** @return list<string> */
    public function getKeywordList(): array;

    /** @return list<string> */
    public function getOperators(): array;

    /** @return list<string> */
    public function getSupportedAggregateFunctions(): array;
}
