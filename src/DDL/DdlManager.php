<?php

declare(strict_types=1);

namespace SQLCraft\DDL;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\DDL\DdlBuilderInterface;
use SQLCraft\Contracts\Execution\QueryExecutorInterface;
use SQLCraft\DDL\Sqlite\TableRecreationStrategy;

final readonly class DdlManager
{
    public function __construct(
        private QueryExecutorInterface $executor,
        private ?TableRecreationStrategy $sqliteRecreation = null,
    ) {
    }

    /** @return list<string> */
    public function preview(ConnectionInterface $connection, DdlBuilderInterface $builder): array
    {
        return $builder->toSql($connection->getPlatform());
    }

    public function execute(ConnectionInterface $connection, DdlBuilderInterface $builder): void
    {
        if ($builder instanceof AlterTableBuilder && $this->sqliteRecreation instanceof TableRecreationStrategy && $connection->getPlatformName() === 'sqlite') {
            $this->sqliteRecreation->execute($connection, $builder);

            return;
        }

        foreach ($this->preview($connection, $builder) as $sql) {
            $this->executor->executeDdl($connection, $sql);
        }
    }
}
