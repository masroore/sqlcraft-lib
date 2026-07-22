<?php

declare(strict_types=1);

namespace SQLCraft\DDL;

use SQLCraft\Contracts\DDL\DdlBuilderInterface;
use SQLCraft\Contracts\DDL\ObjectNameAwareDdlBuilderInterface;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\ValueObjects\QualifiedName;

final readonly class CopyTableBuilder implements DdlBuilderInterface, ObjectNameAwareDdlBuilderInterface
{
    use LegacyDdlExecution;

    public function __construct(public QualifiedName $source, public QualifiedName $target, public bool $includeData = true) {}

    /** @return list<string> */
    #[\Override]
    public function toSql(DdlDialectInterface $dialect): array
    {
        return [$dialect->renderCopyTableStatement($this->source, $this->target, $this->includeData)];
    }

    #[\Override]
    public function getObjectName(): string
    {
        return $this->target->object->name;
    }
}
