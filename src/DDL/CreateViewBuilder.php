<?php

declare(strict_types=1);

namespace SQLCraft\DDL;

use SQLCraft\Contracts\DDL\DdlBuilderInterface;
use SQLCraft\Contracts\DDL\ObjectNameAwareDdlBuilderInterface;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final readonly class CreateViewBuilder implements DdlBuilderInterface, ObjectNameAwareDdlBuilderInterface
{
    use LegacyDdlExecution;

    /** @param list<Identifier> $columns */
    public function __construct(
        public QualifiedName $name,
        public string $selectSql,
        public bool $orReplace = false,
        public array $columns = [],
        public ?string $checkOption = null,
    ) {
    }

    /** @return list<string> */
    #[\Override]
    public function toSql(DdlDialectInterface $dialect): array
    {
        return [$dialect->renderCreateViewStatement($this->name, $this->selectSql, $this->orReplace, $this->columns, $this->checkOption)];
    }

    #[\Override]
    public function getObjectName(): string
    {
        return $this->name->object->name;
    }
}
