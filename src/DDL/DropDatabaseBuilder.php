<?php

declare(strict_types=1);

namespace SQLCraft\DDL;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\DDL\DdlBuilderInterface;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\ValueObjects\Identifier;

final readonly class DropDatabaseBuilder implements DdlBuilderInterface, \SQLCraft\Contracts\DDL\ObjectNameAwareDdlBuilderInterface
{
    use LegacyDdlExecution;
    public function __construct(public Identifier $name, public bool $ifExists = false)
    {
    }

    /** @return list<string> */
    #[\Override]
    public function toSql(DdlDialectInterface $dialect): array
    {
        return [$dialect->renderDropDatabaseStatement($this->name, $this->ifExists)];
    }


    #[\Override]
    public function getObjectName(): string
    {
        return $this->name->name;
    }

}
