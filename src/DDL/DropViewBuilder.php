<?php

declare(strict_types=1);

namespace SQLCraft\DDL;

use SQLCraft\Contracts\DDL\DdlBuilderInterface;
use SQLCraft\Contracts\DDL\ObjectNameAwareDdlBuilderInterface;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\ValueObjects\QualifiedName;

final readonly class DropViewBuilder implements DdlBuilderInterface, ObjectNameAwareDdlBuilderInterface
{
    use LegacyDdlExecution;

    public function __construct(
        public QualifiedName $name,
        public bool $ifExists = false,
        public bool $cascade = false,
    ) {}

    /** @return list<string> */
    #[\Override]
    public function toSql(DdlDialectInterface $dialect): array
    {
        return [$dialect->renderDropViewStatement($this->name, $this->ifExists, $this->cascade)];
    }

    #[\Override]
    public function getObjectName(): string
    {
        return $this->name->object->name;
    }
}
