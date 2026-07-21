<?php

declare(strict_types=1);

namespace SQLCraft\DDL;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\DDL\DdlBuilderInterface;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\Contracts\DDL\IndexDefinitionInterface;
use SQLCraft\ValueObjects\QualifiedName;

final readonly class CreateIndexBuilder implements DdlBuilderInterface
{
    public function __construct(
        public QualifiedName $table,
        public IndexDefinitionInterface $index,
        public bool $ifNotExists = false,
    ) {
    }

    #[\Override]
    public function toSql(DdlDialectInterface $dialect): array
    {
        return [$dialect->renderDdlCreateIndexStatement($this->table, $this->index)];
    }

    #[\Override]
    public function execute(ConnectionInterface $connection): void
    {
        foreach ($this->toSql($connection->getPlatform()) as $sql) {
            $connection->execute($sql);
        }
    }
}
