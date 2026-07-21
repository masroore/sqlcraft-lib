<?php

declare(strict_types=1);

namespace SQLCraft\DDL;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Capabilities\Capability;
use SQLCraft\Contracts\DDL\DdlBuilderInterface;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\ValueObjects\Identifier;

final readonly class CreateSchemaBuilder implements DdlBuilderInterface
{
    public function __construct(public Identifier $name, public ?string $authorization = null, public bool $ifNotExists = false)
    {
    }

    /** @return list<string> */
    #[\Override]
    public function toSql(DdlDialectInterface $dialect): array
    {
        return [$dialect->renderCreateSchemaStatement($this->name, $this->authorization, $this->ifNotExists)];
    }

    #[\Override]
    public function execute(ConnectionInterface $connection): void
    {
        $connection->getPlatform()->getCapabilitySet($connection->getServerVersion())->require(Capability::Scheme);

        foreach ($this->toSql($connection->getPlatform()) as $sql) {
            $connection->execute($sql);
        }
    }
}
