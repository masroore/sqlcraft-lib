<?php

declare(strict_types=1);

namespace SQLCraft\DDL;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\DDL\DdlBuilderInterface;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\ValueObjects\Identifier;

final readonly class CreateDatabaseBuilder implements DdlBuilderInterface, \SQLCraft\Contracts\DDL\ObjectNameAwareDdlBuilderInterface
{
    use LegacyDdlExecution;
    public function __construct(public Identifier $name, public ?string $charset = null, public ?string $collation = null, public bool $ifNotExists = false)
    {
    }

    /** @return list<string> */
    #[\Override]
    public function toSql(DdlDialectInterface $dialect): array
    {
        return [$dialect->renderCreateDatabaseStatement($this->name, $this->charset, $this->collation, $this->ifNotExists)];
    }


    #[\Override]
    public function getObjectName(): string
    {
        return $this->name->name;
    }

}
