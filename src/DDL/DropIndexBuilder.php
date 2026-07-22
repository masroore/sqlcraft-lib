<?php

declare(strict_types=1);

namespace SQLCraft\DDL;

use SQLCraft\Contracts\DDL\DdlBuilderInterface;
use SQLCraft\Contracts\DDL\ObjectNameAwareDdlBuilderInterface;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final readonly class DropIndexBuilder implements DdlBuilderInterface, ObjectNameAwareDdlBuilderInterface
{
    use LegacyDdlExecution;

    public function __construct(
        public QualifiedName $table,
        public Identifier $indexName,
        public bool $ifExists = false,
    ) {}

    #[\Override]
    public function toSql(DdlDialectInterface $dialect): array
    {
        return [$dialect->renderDropIndexStatement($this->table, $this->indexName)];
    }

    #[\Override]
    public function getObjectName(): string
    {
        return $this->indexName->name;
    }
}
