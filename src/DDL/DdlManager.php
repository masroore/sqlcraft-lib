<?php

declare(strict_types=1);

namespace SQLCraft\DDL;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\DDL\DdlBuilderInterface;
use SQLCraft\Contracts\Execution\QueryExecutorInterface;

final readonly class DdlManager
{
    public function __construct(private QueryExecutorInterface $executor)
    {
    }

    /** @return list<string> */
    public function preview(ConnectionInterface $connection, DdlBuilderInterface $builder): array
    {
        return $builder->toSql($connection->getPlatform());
    }

    public function execute(ConnectionInterface $connection, DdlBuilderInterface $builder): void
    {
        foreach ($this->preview($connection, $builder) as $sql) {
            $this->executor->executeDdl($connection, $sql);
        }
    }
}
