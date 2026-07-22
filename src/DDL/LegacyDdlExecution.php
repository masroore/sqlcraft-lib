<?php

declare(strict_types=1);

namespace SQLCraft\DDL;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Execution\QueryExecutor;

/** @internal Compatibility bridge; DdlManager remains the public execution path. */
trait LegacyDdlExecution
{
    public function execute(ConnectionInterface $connection): void
    {
        (new DdlManager(new QueryExecutor))->execute($connection, $this);
    }
}
