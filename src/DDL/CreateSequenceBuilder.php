<?php

declare(strict_types=1);

namespace SQLCraft\DDL;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\DDL\DdlBuilderInterface;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\ValueObjects\Identifier;

final readonly class CreateSequenceBuilder implements DdlBuilderInterface, \SQLCraft\Contracts\DDL\ObjectNameAwareDdlBuilderInterface
{
    use LegacyDdlExecution;
    public function __construct(
        public Identifier $name,
        public int $start = 1,
        public int $increment = 1,
        public ?int $min = null,
        public ?int $max = null,
        public bool $cycle = false,
        public ?int $cache = null,
    ) {
    }

    /** @return list<string> */
    #[\Override]
    public function toSql(DdlDialectInterface $dialect): array
    {
        return [$dialect->renderCreateSequenceStatement($this->name, $this->start, $this->increment, $this->min, $this->max, $this->cycle, $this->cache)];
    }


    #[\Override]
    public function getObjectName(): string
    {
        return $this->name->name;
    }

}
