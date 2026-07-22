<?php

declare(strict_types=1);

namespace SQLCraft\DDL;

use SQLCraft\Contracts\DDL\DdlBuilderInterface;
use SQLCraft\Contracts\DDL\ObjectNameAwareDdlBuilderInterface;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\ValueObjects\QualifiedName;

final readonly class DropTableBuilder implements DdlBuilderInterface, ObjectNameAwareDdlBuilderInterface
{
    use LegacyDdlExecution;

    public function __construct(
        public QualifiedName $table,
        public bool $ifExists = false,
        public bool $cascade = false,
    ) {}

    #[\Override]
    public function toSql(DdlDialectInterface $dialect): array
    {
        return [$dialect->renderDropTableStatement($this->table, $this->ifExists, $this->cascade)];
    }

    #[\Override]
    public function getObjectName(): string
    {
        return $this->table->object->name;
    }
}
