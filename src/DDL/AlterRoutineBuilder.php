<?php

declare(strict_types=1);

namespace SQLCraft\DDL;

use SQLCraft\Contracts\DDL\DdlBuilderInterface;
use SQLCraft\Contracts\DDL\RoutineParameterDefinitionInterface;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\QualifiedName;

final readonly class AlterRoutineBuilder implements DdlBuilderInterface, \SQLCraft\Contracts\DDL\ObjectNameAwareDdlBuilderInterface
{
    use LegacyDdlExecution;
    /** @param list<RoutineParameterDefinitionInterface> $parameters */
    public function __construct(public QualifiedName $name, public string $type, public array $parameters = [], public ?DataType $returnType = null, public string $body = '', public ?string $language = null, public bool $deterministic = false)
    {
    }
    /** @return list<string> */
    #[\Override]
    public function toSql(DdlDialectInterface $dialect): array
    {
        return [$dialect->renderAlterRoutineStatement($this->name, $this->type, $this->parameters, $this->returnType, $this->body, $this->language, $this->deterministic)];
    }
    #[\Override]
    public function getObjectName(): string
    {
        return $this->name->object->name;
    }
}
