<?php

declare(strict_types=1);

namespace SQLCraft\DDL;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\DDL\DdlBuilderInterface;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\ValueObjects\QualifiedName;

final readonly class DropRoutineBuilder implements DdlBuilderInterface, \SQLCraft\Contracts\DDL\ObjectNameAwareDdlBuilderInterface
{
    use LegacyDdlExecution;
    public function __construct(
        public QualifiedName $name,
        public string $type,
        public bool $ifExists = false,
    ) {
    }

    /** @return list<string> */
    #[\Override]
    public function toSql(DdlDialectInterface $dialect): array
    {
        return [$dialect->renderDropRoutineStatement($this->name, $this->type, $this->ifExists)];
    }


    #[\Override]
    public function getObjectName(): string
    {
        return $this->name->object->name;
    }

}
