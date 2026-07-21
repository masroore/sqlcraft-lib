<?php

declare(strict_types=1);

namespace SQLCraft\DDL;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Capabilities\Capability;
use SQLCraft\Contracts\DDL\DdlBuilderInterface;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\ValueObjects\Identifier;

final readonly class DropSchemaBuilder implements DdlBuilderInterface, \SQLCraft\Contracts\DDL\ObjectNameAwareDdlBuilderInterface
{
    public function __construct(public Identifier $name, public bool $ifExists = false, public bool $cascade = false)
    {
    }

    /** @return list<string> */
    #[\Override]
    public function toSql(DdlDialectInterface $dialect): array
    {
        return [$dialect->renderDropSchemaStatement($this->name, $this->ifExists, $this->cascade)];
    }


    #[\Override]
    public function getObjectName(): string
    {
        return $this->name->name;
    }




    #[\Override]
    public function execute(\SQLCraft\Contracts\Connection\ConnectionInterface $connection): void
    {
        $connection->getPlatform()->getCapabilitySet($connection->getServerVersion())->require(\SQLCraft\Capabilities\Capability::Scheme);
        (new DdlManager(new \SQLCraft\Execution\QueryExecutor()))->execute($connection, $this);
    }

}
