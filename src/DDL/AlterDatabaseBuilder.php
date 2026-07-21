<?php

declare(strict_types=1);

namespace SQLCraft\DDL;

use SQLCraft\Contracts\DDL\DdlBuilderInterface;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\ValueObjects\Identifier;

final readonly class AlterDatabaseBuilder implements DdlBuilderInterface, \SQLCraft\Contracts\DDL\ObjectNameAwareDdlBuilderInterface
{
    use LegacyDdlExecution;
    public function __construct(public Identifier $name, public ?string $charset = null, public ?string $collation = null)
    {
    }
    /** @return list<string> */
    #[\Override]
    public function toSql(DdlDialectInterface $dialect): array
    {
        return [$dialect->renderAlterDatabaseStatement($this->name, $this->charset, $this->collation)];
    }
    #[\Override]
    public function getObjectName(): string
    {
        return $this->name->name;
    }
}
