<?php

declare(strict_types=1);

namespace SQLCraft\DDL;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\DDL\DdlBuilderInterface;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\ValueObjects\QualifiedName;

final readonly class DropTableBuilder implements DdlBuilderInterface
{
    public function __construct(
        public QualifiedName $table,
        public bool $ifExists = false,
        public bool $cascade = false,
    ) {
    }

    #[\Override]
    public function toSql(DdlDialectInterface $dialect): array
    {
        return [$dialect->renderDropTableStatement($this->table, $this->ifExists, $this->cascade)];
    }

    #[\Override]
    public function execute(ConnectionInterface $connection): void
    {
        foreach ($this->toSql($connection->getPlatform()) as $sql) {
            $connection->execute($sql);
        }
    }
}
