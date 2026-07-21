<?php

declare(strict_types=1);

namespace SQLCraft\DDL;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\DDL\DdlBuilderInterface;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\ValueObjects\QualifiedName;

final readonly class DropRoutineBuilder implements DdlBuilderInterface
{
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
    public function execute(ConnectionInterface $connection): void
    {
        foreach ($this->toSql($connection->getPlatform()) as $sql) {
            $connection->execute($sql);
        }
    }
}
