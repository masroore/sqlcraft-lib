<?php

declare(strict_types=1);

namespace SQLCraft\DDL;

use SQLCraft\Contracts\DDL\DdlBuilderInterface;
use SQLCraft\Contracts\DDL\ObjectNameAwareDdlBuilderInterface;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\ValueObjects\QualifiedName;

final readonly class TruncateBuilder implements DdlBuilderInterface, ObjectNameAwareDdlBuilderInterface
{
    use LegacyDdlExecution;

    public function __construct(
        public QualifiedName $table,
        public bool $cascade = false,
        public bool $restartIdentity = false,
    ) {}

    /** @return list<string> */
    #[\Override]
    public function toSql(DdlDialectInterface $dialect): array
    {
        return [$dialect->renderTruncateStatement($this->table, $this->cascade, $this->restartIdentity)];
    }

    #[\Override]
    public function getObjectName(): string
    {
        return $this->table->object->name;
    }
}
